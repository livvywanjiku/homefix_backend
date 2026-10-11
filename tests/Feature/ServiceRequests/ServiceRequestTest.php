<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\PreferredTimeSlot;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestUrgency;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestImage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A customer posting and managing a job (spec §8).
 *
 * The negative cases carry the weight here. Posting a request is the first
 * write in the marketplace, and it is reachable by anyone who signs up, so the
 * questions that matter are who *cannot* do it and what the platform refuses to
 * take from the client.
 */
class ServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function customer(): User
    {
        return User::factory()->customer()->create();
    }

    /**
     * A real one-pixel PNG. `UploadedFile::fake()->image()` needs GD, which is
     * not installed here, and decoding genuine bytes also exercises the `image`
     * rule rather than just the filename.
     */
    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => Service::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'title' => 'Kitchen tap is dripping',
            'description' => 'The cold tap in the kitchen has dripped for a week and the washer needs replacing.',
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Guest access
    |--------------------------------------------------------------------------
    */

    /**
     * The guest-access rule, enforced at the API rather than by hiding a button:
     * a visitor posting straight to the endpoint is refused.
     */
    public function test_a_guest_cannot_post_a_request(): void
    {
        $this->postJson('/api/service-requests', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_a_guest_cannot_read_the_request_list(): void
    {
        $this->getJson('/api/service-requests')->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_cannot_post_a_request(): void
    {
        // Being able to bid on jobs is not the same as being able to create
        // them; the role gate stops the trade posting to their own board.
        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload())
            ->assertForbidden();
    }

    public function test_an_admin_cannot_post_a_request(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload())
            ->assertForbidden();
    }

    public function test_a_customer_cannot_read_another_customers_request(): void
    {
        $request = ServiceRequest::factory()->create();
        $intruder = $this->customer();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/service-requests/{$request->id}")
            ->assertForbidden();
    }

    public function test_the_list_contains_only_the_callers_own_requests(): void
    {
        $mine = ServiceRequest::factory()->create();
        ServiceRequest::factory()->create();

        $this->actingAs($mine->customer, 'sanctum')
            ->getJson('/api/service-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Posting
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_can_post_a_request(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/service-requests', $this->payload([
                'budget_min_cents' => 500000,
                'budget_max_cents' => 1200000,
                'urgency' => ServiceRequestUrgency::Urgent->value,
                'preferred_date' => now()->addDays(3)->toDateString(),
                'preferred_time_slot' => PreferredTimeSlot::Morning->value,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', ServiceRequestStatus::Open->value)
            ->assertJsonPath('data.urgency', ServiceRequestUrgency::Urgent->value)
            ->assertJsonPath('data.quotes_count', 0);

        // The reference and the deadline are issued by the platform, not sent
        // by the customer.
        $this->assertMatchesRegularExpression('/^REQ-\d{6}$/', $response->json('data.reference'));

        $this->assertDatabaseHas('service_requests', [
            'customer_id' => $customer->id,
            'status' => ServiceRequestStatus::Open->value,
        ]);
    }

    public function test_the_deadline_is_set_by_the_platform_and_the_client_cannot_move_it(): void
    {
        $response = $this->actingAs($this->customer(), 'sanctum')->postJson(
            '/api/service-requests',
            $this->payload([
                // Neither of these is a column a customer may assert.
                'status' => ServiceRequestStatus::Awarded->value,
                'quotes_count' => 99,
                'expires_at' => now()->addYear()->toIso8601String(),
            ]),
        );

        $response->assertCreated()->assertJsonPath('data.status', ServiceRequestStatus::Open->value);

        $request = ServiceRequest::firstOrFail();

        $this->assertSame(0, $request->quotes_count);
        $this->assertTrue($request->expires_at->isSameDay(now()->addDays(ServiceRequest::LIFETIME_DAYS)));
    }

    public function test_a_request_must_name_an_active_service_and_location(): void
    {
        $retiredService = Service::factory()->inactive()->create();
        $retiredLocation = Location::factory()->inactive()->create();

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload(['service_id' => $retiredService->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload(['location_id' => $retiredLocation->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_a_description_too_short_to_quote_from_is_rejected(): void
    {
        // A professional cannot price a job from "fix it".
        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload(['description' => 'Fix it']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_a_budget_ceiling_below_its_floor_is_rejected(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload([
                'budget_min_cents' => 900000,
                'budget_max_cents' => 100000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('budget_max_cents');
    }

    public function test_a_preferred_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload([
                'preferred_date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_date');
    }

    /**
     * A pin needs both halves. One coordinate alone cannot be placed on a map,
     * and defaulting the other to zero would drop the customer in the Atlantic.
     */
    public function test_half_a_coordinate_pair_is_rejected(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload(['latitude' => -0.3031]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('longitude');
    }

    /*
    |--------------------------------------------------------------------------
    | Editing and cancelling
    |--------------------------------------------------------------------------
    */

    public function test_an_open_request_can_be_edited(): void
    {
        $request = ServiceRequest::factory()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->putJson("/api/service-requests/{$request->id}", ['title' => 'Kitchen tap and sink'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Kitchen tap and sink');
    }

    /**
     * Once a professional has priced the job, the brief is frozen: letting it
     * change afterwards would mean they had quoted against something else.
     */
    public function test_a_quoted_request_can_no_longer_be_edited(): void
    {
        $request = ServiceRequest::factory()->quoted()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->putJson("/api/service-requests/{$request->id}", ['title' => 'Something else entirely'])
            ->assertForbidden();

        $this->assertNotSame('Something else entirely', $request->fresh()->title);
    }

    public function test_a_lapsed_request_can_no_longer_be_edited(): void
    {
        $request = ServiceRequest::factory()->expired()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->putJson("/api/service-requests/{$request->id}", ['title' => 'Still interested'])
            ->assertForbidden();
    }

    public function test_a_customer_can_cancel_their_request(): void
    {
        $request = ServiceRequest::factory()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->postJson("/api/service-requests/{$request->id}/cancel", ['reason' => 'Found somebody locally'])
            ->assertOk()
            ->assertJsonPath('data.status', ServiceRequestStatus::Cancelled->value);

        $this->assertSame(ServiceRequestStatus::Cancelled, $request->fresh()->status);
    }

    /**
     * Withdrawing a request that has already produced a booking would leave the
     * booking without a parent, so the transition is refused.
     */
    public function test_an_awarded_request_cannot_be_cancelled(): void
    {
        $request = ServiceRequest::factory()->awarded()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->postJson("/api/service-requests/{$request->id}/cancel")
            ->assertForbidden();

        $this->assertSame(ServiceRequestStatus::Awarded, $request->fresh()->status);
    }

    public function test_a_customer_cannot_cancel_another_customers_request(): void
    {
        $request = ServiceRequest::factory()->create();

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/service-requests/{$request->id}/cancel")
            ->assertForbidden();

        $this->assertSame(ServiceRequestStatus::Open, $request->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Photos
    |--------------------------------------------------------------------------
    */

    public function test_photos_are_stored_when_the_request_is_posted(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->customer(), 'sanctum')->postJson(
            '/api/service-requests',
            $this->payload(['images' => [$this->fakeImage('tap.png'), $this->fakeImage('sink.png')]]),
        );

        $response->assertCreated()->assertJsonCount(2, 'data.images');

        foreach (ServiceRequestImage::pluck('path') as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/service-requests', $this->payload([
                'images' => [UploadedFile::fake()->create('quote.pdf', 60, 'application/pdf')],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images.0');

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_the_total_photo_count_is_capped(): void
    {
        Storage::fake('public');

        $request = ServiceRequest::factory()->create();

        // Fill the allowance first.
        ServiceRequestImage::factory()->count(ServiceRequest::MAX_IMAGES)->forRequest($request)->create();

        $this->actingAs($request->customer, 'sanctum')
            ->postJson("/api/service-requests/{$request->id}/images", [
                'images' => [$this->fakeImage('extra.png')],
            ])
            ->assertStatus(422);

        $this->assertSame(ServiceRequest::MAX_IMAGES, $request->images()->count());

        // The rejected upload must not be left behind on disk.
        $this->assertCount(ServiceRequest::MAX_IMAGES, Storage::disk('public')->allFiles());
    }

    public function test_a_photo_can_be_removed(): void
    {
        Storage::fake('public');

        $request = ServiceRequest::factory()->create();
        $image = ServiceRequestImage::factory()->forRequest($request)->create([
            'path' => 'service-requests/remove-me.jpg',
        ]);

        Storage::disk('public')->put($image->path, 'x');

        $this->actingAs($request->customer, 'sanctum')
            ->deleteJson("/api/service-requests/{$request->id}/images/{$image->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('service_request_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('service-requests/remove-me.jpg');
    }

    /**
     * The image id and the request id in the path must agree. Without that
     * check, pairing one's own request with somebody else's image id would
     * delete their photo.
     */
    public function test_a_photo_cannot_be_removed_through_a_different_request(): void
    {
        Storage::fake('public');

        $mine = ServiceRequest::factory()->create();
        $theirs = ServiceRequest::factory()->create();
        $theirImage = ServiceRequestImage::factory()->forRequest($theirs)->create();

        $this->actingAs($mine->customer, 'sanctum')
            ->deleteJson("/api/service-requests/{$mine->id}/images/{$theirImage->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('service_request_images', ['id' => $theirImage->id]);
    }

    public function test_a_photo_cannot_be_added_to_a_quoted_request(): void
    {
        Storage::fake('public');

        $request = ServiceRequest::factory()->quoted()->create();

        $this->actingAs($request->customer, 'sanctum')
            ->postJson("/api/service-requests/{$request->id}/images", [
                'images' => [$this->fakeImage('late.png')],
            ])
            ->assertForbidden();
    }
}
