<?php

namespace Tests\Feature\Professional;

use App\Enums\DocumentType;
use App\Enums\PricingType;
use App\Enums\VerificationStatus;
use App\Models\AvailabilityException;
use App\Models\Location;
use App\Models\PortfolioImage;
use App\Models\PortfolioItem;
use App\Models\ProfessionalDocument;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The professional self-service surface: profile, offerings, availability,
 * portfolio and documents.
 *
 * The emphasis is on the negative cases — a customer reaching a professional
 * endpoint, and one professional acting on another's records — because those
 * are the failures that would not show up by clicking through the app.
 */
class ProfessionalProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function professional(): User
    {
        return User::factory()->professional()->create();
    }

    private function profileFor(?User $user = null): ProfessionalProfile
    {
        $user ??= $this->professional();

        return ProfessionalProfile::factory()->forUser($user)->create();
    }

    /**
     * A real one-pixel PNG, uploaded as if chosen in a file input.
     *
     * The GD extension is not installed on this machine, so
     * `UploadedFile::fake()->image()` cannot generate one. Decoding a known
     * PNG instead also means the `image` rule is exercised against genuine
     * image bytes rather than a filename.
     */
    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_the_professional_endpoints_require_authentication(): void
    {
        $this->getJson('/api/professional/profile')->assertUnauthorized();
    }

    /**
     * A customer is not a professional: the role gate must stop them before any
     * controller runs, whatever they send.
     */
    public function test_a_customer_cannot_reach_the_professional_endpoints(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/professional/profile')
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/professional/profile', ['business_name' => 'Not Mine'])
            ->assertForbidden();
    }

    public function test_an_admin_cannot_use_the_professional_self_service_surface(): void
    {
        // Being an administrator is not a superset of being a professional:
        // the admin reviews applications through the admin endpoints instead.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/professional/profile')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_without_a_profile_receives_null(): void
    {
        $this->actingAs($this->professional(), 'sanctum')
            ->getJson('/api/professional/profile')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_a_professional_can_create_their_profile(): void
    {
        $user = $this->professional();
        $base = Location::factory()->create(['name' => 'Nakuru', 'region' => 'Nakuru County']);
        $second = Location::factory()->create(['name' => 'Naivasha', 'region' => 'Nakuru County']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/professional/profile', [
            'business_name' => 'Otieno Plumbing Services',
            'about' => 'Twenty years of pipework across Nakuru.',
            'experience_years' => 20,
            'phone' => '+254700111222',
            'base_location_id' => $base->id,
            'service_area_ids' => [$base->id, $second->id],
        ]);

        // The first save creates the profile, so the response is 201; later
        // saves of the same endpoint are 200.
        $response->assertCreated()
            ->assertJsonPath('data.business_name', 'Otieno Plumbing Services')
            ->assertJsonPath('data.verification_status', VerificationStatus::Pending->value)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonCount(2, 'data.service_areas');

        $this->assertDatabaseHas('professional_profiles', [
            'user_id' => $user->id,
            'business_name' => 'Otieno Plumbing Services',
            'experience_years' => 20,
        ]);

        $this->assertDatabaseCount('professional_locations', 2);
    }

    /**
     * The editor sends the whole set of areas, so a save must remove what the
     * professional unticked rather than only adding.
     */
    public function test_saving_the_profile_replaces_the_service_areas(): void
    {
        $user = $this->professional();
        $first = Location::factory()->create();
        $second = Location::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/professional/profile', [
            'business_name' => 'Otieno Plumbing Services',
            'service_area_ids' => [$first->id, $second->id],
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->putJson('/api/professional/profile', [
            'business_name' => 'Otieno Plumbing Services',
            'service_area_ids' => [$second->id],
        ])->assertOk()->assertJsonCount(1, 'data.service_areas');

        $profile = $user->fresh()->professionalProfile;

        $this->assertSame([$second->id], $profile->serviceAreas->pluck('id')->all());
    }

    public function test_the_profile_cannot_be_pointed_at_an_inactive_location(): void
    {
        $user = $this->professional();
        $retired = Location::factory()->inactive()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/professional/profile', [
            'business_name' => 'Otieno Plumbing Services',
            'base_location_id' => $retired->id,
        ])->assertStatus(422)->assertJsonValidationErrors('base_location_id');
    }

    /**
     * The account's own email is not part of a professional profile. Only an
     * administrator sees it, and only in the review queue.
     */
    public function test_the_profile_payload_never_includes_the_account_email(): void
    {
        $user = $this->professional();
        $this->profileFor($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/profile')
            ->assertOk()
            ->assertJsonMissingPath('data.user.email');
    }

    /*
    |--------------------------------------------------------------------------
    | Offerings
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_can_list_a_fixed_price_service(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->create(['name' => 'Leak Repair']);

        $response = $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Fixed->value,
            'price_min_cents' => 150000,
            'price_max_cents' => 400000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.price_min_cents', 150000)
            ->assertJsonPath('data.pricing_type', PricingType::Fixed->value);

        // The "from" figure on a search card comes from the floor price.
        $this->assertSame(150000, $profile->fresh(['services'])->lowestPriceCents());
    }

    /**
     * A quote-on-inspection offering must not carry figures at all — a stored
     * zero would render as "KSh 0" and read as free.
     */
    public function test_a_quote_only_service_discards_any_price_sent_with_it(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->create();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Quote->value,
            'price_min_cents' => 5000,
            'price_max_cents' => 9000,
        ])->assertCreated()
            ->assertJsonPath('data.price_min_cents', null)
            ->assertJsonPath('data.price_max_cents', null);

        $this->assertDatabaseHas('professional_services', [
            'professional_profile_id' => $profile->id,
            'price_min_cents' => null,
        ]);
    }

    public function test_a_priced_service_requires_a_price(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->create();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Hourly->value,
        ])->assertStatus(422)->assertJsonValidationErrors('price_min_cents');
    }

    public function test_a_price_range_must_be_the_right_way_round(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->create();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Fixed->value,
            'price_min_cents' => 900000,
            'price_max_cents' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('price_max_cents');
    }

    public function test_the_same_service_cannot_be_listed_twice(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->create();
        ProfessionalService::factory()->forProfile($profile)->forService($service)->create();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Quote->value,
        ])->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_a_retired_catalogue_service_cannot_be_offered(): void
    {
        $profile = $this->profileFor();
        $service = Service::factory()->inactive()->create();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/services', [
            'service_id' => $service->id,
            'pricing_type' => PricingType::Quote->value,
        ])->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_a_professional_cannot_touch_another_professionals_offering(): void
    {
        $offering = ProfessionalService::factory()->create();
        $intruder = $this->professional();

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/professional/services/{$offering->id}", [
                'pricing_type' => PricingType::Quote->value,
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/professional/services/{$offering->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('professional_services', ['id' => $offering->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    public function test_the_weekly_pattern_is_replaced_wholesale(): void
    {
        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')->putJson('/api/professional/availability', [
            'days' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00'],
                ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '17:00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        // Saving a shorter week must remove the day that was dropped, not leave
        // it behind.
        $this->actingAs($profile->user, 'sanctum')->putJson('/api/professional/availability', [
            'days' => [
                ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '15:00'],
            ],
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('availabilities', 1);
        $this->assertDatabaseHas('availabilities', [
            'professional_profile_id' => $profile->id,
            'day_of_week' => 2,
        ]);
    }

    public function test_a_day_cannot_finish_before_it_starts(): void
    {
        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')->putJson('/api/professional/availability', [
            'days' => [
                ['day_of_week' => 3, 'start_time' => '17:00', 'end_time' => '08:00'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('days.0.end_time');
    }

    public function test_a_professional_can_block_and_unblock_a_date(): void
    {
        $profile = $this->profileFor();
        $date = now()->addWeek()->toDateString();

        $response = $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/availability/exceptions', [
                'blocked_on' => $date,
                'reason' => 'Public holiday',
            ]);

        $response->assertCreated()->assertJsonPath('data.blocked_on', $date);

        $id = $response->json('data.id');

        $this->actingAs($profile->user, 'sanctum')
            ->deleteJson("/api/professional/availability/exceptions/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('availability_exceptions', ['id' => $id]);
    }

    public function test_blocking_the_same_date_twice_updates_the_reason(): void
    {
        $profile = $this->profileFor();
        $date = now()->addWeek()->toDateString();

        $this->actingAs($profile->user, 'sanctum')->postJson(
            '/api/professional/availability/exceptions',
            ['blocked_on' => $date, 'reason' => 'Leave'],
        )->assertCreated();

        $this->actingAs($profile->user, 'sanctum')->postJson(
            '/api/professional/availability/exceptions',
            ['blocked_on' => $date, 'reason' => 'Training course'],
        )->assertOk();

        $this->assertDatabaseCount('availability_exceptions', 1);
        $this->assertDatabaseHas('availability_exceptions', ['reason' => 'Training course']);
    }

    public function test_a_date_in_the_past_cannot_be_blocked(): void
    {
        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')
            ->postJson('/api/professional/availability/exceptions', [
                'blocked_on' => now()->subWeek()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('blocked_on');
    }

    public function test_a_professional_cannot_unblock_another_professionals_date(): void
    {
        $exception = AvailabilityException::factory()->create();
        $intruder = $this->professional();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/professional/availability/exceptions/{$exception->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('availability_exceptions', ['id' => $exception->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Portfolio
    |--------------------------------------------------------------------------
    */

    public function test_a_portfolio_item_stores_its_photos_and_picks_a_cover(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $response = $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/portfolio', [
            'title' => 'Bathroom refit in Milimani',
            'description' => 'Full re-pipe and new fittings.',
            'completed_on' => now()->subMonth()->toDateString(),
            'images' => [
                $this->fakeImage('before.png'),
                $this->fakeImage('after.png'),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Bathroom refit in Milimani')
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.is_cover', true)
            ->assertJsonPath('data.images.1.is_cover', false);

        // The cover URL is what the gallery card renders.
        $this->assertNotNull($response->json('data.cover_url'));

        $this->assertDatabaseCount('portfolio_images', 2);

        $paths = PortfolioImage::pluck('path');
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_a_portfolio_item_rejects_a_file_that_is_not_an_image(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/portfolio', [
            'title' => 'Bathroom refit',
            'images' => [UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    public function test_deleting_a_portfolio_item_removes_its_files(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $item = PortfolioItem::factory()->forProfile($profile)->create();
        $image = PortfolioImage::factory()->forItem($item)->create([
            'path' => 'portfolio/keep-me.jpg',
        ]);

        Storage::disk('public')->put($image->path, 'x');

        $this->actingAs($profile->user, 'sanctum')
            ->deleteJson("/api/professional/portfolio/{$item->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('portfolio_images', ['id' => $image->id]);

        // The rows cascade, but the file on disk would linger without the
        // explicit delete.
        Storage::disk('public')->assertMissing('portfolio/keep-me.jpg');
    }

    public function test_removing_the_cover_photo_promotes_the_next_one(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();
        $item = PortfolioItem::factory()->forProfile($profile)->create();

        $cover = PortfolioImage::factory()->forItem($item)->cover()->create(['sort_order' => 0]);
        $second = PortfolioImage::factory()->forItem($item)->create(['sort_order' => 1]);

        $this->actingAs($profile->user, 'sanctum')
            ->deleteJson("/api/professional/portfolio/{$item->id}/images/{$cover->id}")
            ->assertNoContent();

        $this->assertTrue($second->fresh()->is_cover);
    }

    public function test_a_professional_cannot_delete_another_professionals_portfolio_item(): void
    {
        $item = PortfolioItem::factory()->create();
        $intruder = $this->professional();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/professional/portfolio/{$item->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('portfolio_items', ['id' => $item->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_can_file_a_verification_document(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $response = $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/documents', [
            'type' => DocumentType::NationalId->value,
            'document' => UploadedFile::fake()->create('id.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', DocumentType::NationalId->value)
            ->assertJsonPath('data.status', VerificationStatus::Pending->value);

        $document = ProfessionalDocument::firstOrFail();

        // The path is generated by the storage layer, never supplied by the
        // client, so one professional cannot write over another's file.
        Storage::disk('public')->assertExists($document->path);
        $this->assertStringStartsWith("documents/{$profile->id}/", $document->path);
    }

    public function test_a_document_of_a_disallowed_type_is_rejected(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/documents', [
            'type' => DocumentType::NationalId->value,
            'document' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_an_unknown_document_type_is_rejected(): void
    {
        Storage::fake('public');

        $profile = $this->profileFor();

        $this->actingAs($profile->user, 'sanctum')->postJson('/api/professional/documents', [
            'type' => 'forged_certificate',
            'document' => UploadedFile::fake()->create('id.pdf', 200, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_reviewed_document_cannot_be_withdrawn(): void
    {
        $profile = $this->profileFor();
        $approved = ProfessionalDocument::factory()->forProfile($profile)->approved()->create();

        // Withdrawing approved evidence would erase the basis of a decision.
        $this->actingAs($profile->user, 'sanctum')
            ->deleteJson("/api/professional/documents/{$approved->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('professional_documents', ['id' => $approved->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    public function test_the_dashboard_reports_the_professionals_standing(): void
    {
        $profile = $this->profileFor();
        ProfessionalService::factory()->forProfile($profile)->create();
        ProfessionalService::factory()->forProfile($profile)->inactive()->create();

        $this->actingAs($profile->user, 'sanctum')
            ->getJson('/api/professional/dashboard')
            ->assertOk()
            ->assertJsonPath('data.verification_status', VerificationStatus::Pending->value)

            // Pending means incomplete, and the dashboard has to say so: an
            // unverified professional is invisible in search.
            ->assertJsonPath('data.is_listed', false)
            ->assertJsonPath('data.active_services_count', 1);
    }

    public function test_the_dashboard_requires_a_profile(): void
    {
        $this->actingAs($this->professional(), 'sanctum')
            ->getJson('/api/professional/dashboard')
            ->assertNotFound();
    }
}
