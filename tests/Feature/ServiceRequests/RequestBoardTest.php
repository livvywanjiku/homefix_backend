<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The open-request board a professional works from (spec §8).
 *
 * The board is the first thing in the platform that shows one customer's job to
 * a stranger, so the tests here are about what a browsing professional may see:
 * the job and the town, never the address.
 */
class RequestBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A professional working out of one town, offering one service.
     *
     * @return array{0: User, 1: ProfessionalProfile}
     */
    private function professionalIn(Location $base, ?Service $service = null): array
    {
        $user = User::factory()->professional()->create();
        $profile = ProfessionalProfile::factory()->verified()->forUser($user)->basedIn($base)->create();

        if ($service !== null) {
            ProfessionalService::factory()->forProfile($profile)->forService($service)->create();
        }

        return [$user, $profile];
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_the_board_requires_authentication(): void
    {
        $this->getJson('/api/professional/requests')->assertUnauthorized();
    }

    public function test_a_customer_cannot_read_the_board(): void
    {
        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/professional/requests')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | What appears
    |--------------------------------------------------------------------------
    */

    public function test_the_board_shows_only_jobs_in_the_professionals_services_and_towns(): void
    {
        $nakuru = Location::factory()->create(['name' => 'Nakuru']);
        $mombasa = Location::factory()->create(['name' => 'Mombasa']);

        $plumbing = Service::factory()->create(['name' => 'Leak Repair']);
        $electrical = Service::factory()->create(['name' => 'Wiring']);

        [$user] = $this->professionalIn($nakuru, $plumbing);

        // The one the professional should see.
        $match = ServiceRequest::factory()->forService($plumbing)->inLocation($nakuru)->create();

        // Right trade, wrong town.
        ServiceRequest::factory()->forService($plumbing)->inLocation($mombasa)->create();

        // Right town, wrong trade.
        ServiceRequest::factory()->forService($electrical)->inLocation($nakuru)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_the_whole_board_can_be_read_when_the_scope_is_widened(): void
    {
        $nakuru = Location::factory()->create();
        $mombasa = Location::factory()->create();

        [$user] = $this->professionalIn($nakuru, Service::factory()->create());

        ServiceRequest::factory()->inLocation($nakuru)->create();
        ServiceRequest::factory()->inLocation($mombasa)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * A job that has been awarded or has lapsed is off the board. A professional
     * working a stale listing wastes a quotation.
     */
    public function test_awarded_lapsed_and_cancelled_jobs_stay_off_the_board(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        ServiceRequest::factory()->forService($service)->inLocation($nakuru)->create();
        ServiceRequest::factory()->forService($service)->inLocation($nakuru)->awarded()->create();
        ServiceRequest::factory()->forService($service)->inLocation($nakuru)->expired()->create();
        ServiceRequest::factory()->forService($service)->inLocation($nakuru)->cancelled()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_still_listed_job_is_readable_but_hides_the_customer_address(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        $request = ServiceRequest::factory()->forService($service)->inLocation($nakuru)->create([
            'address' => '14 Kenyatta Avenue, Nakuru',
            'instructions' => 'Gate code 4471.',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/professional/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $request->id);

        // The address is released to the trade only once they have quoted.
        $response->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.instructions')
            ->assertJsonMissingPath('data.latitude');
    }

    public function test_the_owner_still_sees_their_own_address(): void
    {
        $request = ServiceRequest::factory()->create(['address' => '14 Kenyatta Avenue, Nakuru']);

        $this->actingAs($request->customer, 'sanctum')
            ->getJson("/api/service-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.address', '14 Kenyatta Avenue, Nakuru');
    }

    public function test_a_professional_cannot_open_a_job_that_is_no_longer_listed(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        $awarded = ServiceRequest::factory()->forService($service)->inLocation($nakuru)->awarded()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/professional/requests/{$awarded->id}")
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function test_the_urgency_sort_puts_same_day_jobs_first(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        // Created first, so a default `latest` sort would put it on top.
        $urgent = ServiceRequest::factory()->forService($service)->inLocation($nakuru)->urgent()->create();
        $flexible = ServiceRequest::factory()->forService($service)->inLocation($nakuru)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&sort=urgent')
            ->assertOk()
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('data.1.id', $flexible->id);
    }

    public function test_the_budget_filter_keeps_only_jobs_that_can_afford_the_floor(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        $small = ServiceRequest::factory()->forService($service)->inLocation($nakuru)
            ->withBudget(100000, 500000)->create();

        $large = ServiceRequest::factory()->forService($service)->inLocation($nakuru)
            ->withBudget(2000000, 5000000)->create();

        // A job with no stated budget cannot be shown to clear the bar, so it is
        // excluded rather than assumed generous.
        ServiceRequest::factory()->forService($service)->inLocation($nakuru)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&min_budget_cents=1000000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $large->id);

        $this->assertNotSame($small->id, $large->id);
    }

    public function test_a_job_within_the_radius_is_kept_and_one_outside_it_is_not(): void
    {
        // Nakuru and Mombasa, roughly 700 km apart.
        $nakuru = Location::factory()->create([
            'name' => 'Nakuru',
            'latitude' => -0.3031,
            'longitude' => 36.0800,
        ]);
        $mombasa = Location::factory()->create([
            'name' => 'Mombasa',
            'latitude' => -4.0435,
            'longitude' => 39.6682,
        ]);

        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        $near = ServiceRequest::factory()->forService($service)->inLocation($nakuru)->create();
        ServiceRequest::factory()->forService($service)->inLocation($mombasa)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&max_distance_km=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $near->id)

            // The distance is reported so the professional can judge the trip.
            ->assertJsonPath('data.0.distance_km', 0.0);
    }

    /**
     * A job pinned on the map is measured directly, even when its town is far
     * away — the pin is the customer's actual location.
     */
    public function test_a_pinned_job_is_measured_from_its_own_coordinates(): void
    {
        $nakuru = Location::factory()->create([
            'name' => 'Nakuru',
            'latitude' => -0.3031,
            'longitude' => 36.0800,
        ]);
        $mombasa = Location::factory()->create([
            'name' => 'Mombasa',
            'latitude' => -4.0435,
            'longitude' => 39.6682,
        ]);

        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        // Filed under Mombasa but pinned 5 km from the professional.
        $pinned = ServiceRequest::factory()->forService($service)->inLocation($mombasa)
            ->atCoordinates(-0.3481, 36.0800)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&max_distance_km=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pinned->id);
    }

    /**
     * A professional with nowhere set as a base has no origin to measure from,
     * so a radius search fails closed rather than returning the whole country.
     */
    public function test_a_radius_search_returns_nothing_when_the_professional_has_no_base(): void
    {
        $user = User::factory()->professional()->create();
        ProfessionalProfile::factory()->verified()->forUser($user)->create(['base_location_id' => null]);

        ServiceRequest::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&max_distance_km=25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_search_term_matches_the_title_and_the_reference(): void
    {
        $nakuru = Location::factory()->create();
        $service = Service::factory()->create();

        [$user] = $this->professionalIn($nakuru, $service);

        $burst = ServiceRequest::factory()->forService($service)->inLocation($nakuru)
            ->create(['title' => 'Burst pipe under the sink']);

        ServiceRequest::factory()->forService($service)->inLocation($nakuru)
            ->create(['title' => 'Rewire the garage']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?scope=all&q=burst')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $burst->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?q='.$burst->reference)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $burst->id);
    }

    public function test_an_unknown_sort_is_rejected_rather_than_silently_ignored(): void
    {
        $nakuru = Location::factory()->create();

        [$user] = $this->professionalIn($nakuru, Service::factory()->create());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/professional/requests?sort=cheapest')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }
}
