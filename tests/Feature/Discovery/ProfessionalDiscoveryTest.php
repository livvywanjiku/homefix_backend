<?php

namespace Tests\Feature\Discovery;

use App\Models\Availability;
use App\Models\AvailabilityException;
use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public professional search and the public profile it leads to (spec §13–§14).
 *
 * Two rules run through every test here: a visitor may look without an account,
 * and only a verified professional is ever listed. The second is the platform's
 * whole promise, so it is tested from several directions rather than once.
 */
class ProfessionalDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A verified professional based in a town, optionally offering a service.
     */
    private function professional(
        Location $base,
        ?Service $service = null,
        array $attributes = [],
    ): ProfessionalProfile {
        $profile = ProfessionalProfile::factory()
            ->verified()
            ->basedIn($base)
            ->create($attributes);

        if ($service !== null) {
            ProfessionalService::factory()->forProfile($profile)->forService($service)->create();
        }

        return $profile;
    }

    /*
    |--------------------------------------------------------------------------
    | Guest access
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_can_search_without_an_account(): void
    {
        $this->professional(Location::factory()->create(), Service::factory()->create());

        $this->getJson('/api/professionals')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_guest_can_open_a_verified_profile(): void
    {
        $profile = $this->professional(Location::factory()->create());

        $this->getJson("/api/professionals/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.is_verified', true);
    }

    /**
     * The public page is a shop window, not a contact list. The trading name and
     * the town are published; the account's email and the business phone number
     * are not, so a visitor has to go through the request flow to reach anybody.
     */
    public function test_a_public_profile_never_exposes_contact_details(): void
    {
        $profile = $this->professional(Location::factory()->create(), null, [
            'phone' => '+254700111222',
        ]);

        $this->getJson("/api/professionals/{$profile->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.user.email');
    }

    /**
     * The page offers a "request this service" call to action, and the answer to
     * whether a viewer may take it comes from the server rather than from the
     * browser guessing.
     */
    public function test_the_profile_reports_whether_the_viewer_may_post_a_request(): void
    {
        $profile = $this->professional(Location::factory()->create());

        $this->getJson("/api/professionals/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('meta.can_request_service', false);

        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson("/api/professionals/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('meta.can_request_service', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Only verified professionals are listed
    |--------------------------------------------------------------------------
    */

    public function test_an_unreviewed_professional_is_not_listed(): void
    {
        // The factory default is `pending`, which is where a new applicant sits.
        $profile = ProfessionalProfile::factory()->basedIn(Location::factory()->create())->create();

        $this->getJson('/api/professionals')->assertOk()->assertJsonCount(0, 'data');

        // 404 rather than 403: a 403 would confirm the id exists and turn the
        // directory into a way to enumerate who has applied.
        $this->getJson("/api/professionals/{$profile->id}")->assertNotFound();
    }

    public function test_a_rejected_or_suspended_professional_is_not_listed(): void
    {
        $base = Location::factory()->create();

        ProfessionalProfile::factory()->rejected()->basedIn($base)->create();
        ProfessionalProfile::factory()->suspended()->basedIn($base)->create();

        $this->getJson('/api/professionals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_unreviewed_professional_can_still_see_their_own_page(): void
    {
        $profile = ProfessionalProfile::factory()->basedIn(Location::factory()->create())->create();

        $this->actingAs($profile->user, 'sanctum')
            ->getJson("/api/professionals/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function test_search_matches_on_the_offered_service(): void
    {
        $base = Location::factory()->create();
        $plumbing = Service::factory()->create();
        $electrical = Service::factory()->create();

        $plumber = $this->professional($base, $plumbing);
        $this->professional($base, $electrical);

        $this->getJson("/api/professionals?service_id={$plumbing->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $plumber->id);
    }

    public function test_search_matches_on_the_service_category(): void
    {
        $base = Location::factory()->create();
        $trades = ServiceCategory::factory()->create();
        $home = ServiceCategory::factory()->create();

        $inTrades = $this->professional($base, Service::factory()->inCategory($trades)->create());
        $this->professional($base, Service::factory()->inCategory($home)->create());

        $this->getJson("/api/professionals?service_category_id={$trades->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inTrades->id);
    }

    /**
     * The base town counts as covered, so a professional does not have to list
     * their own town a second time in their service areas.
     */
    public function test_search_covers_the_base_town_and_the_declared_service_areas(): void
    {
        $nakuru = Location::factory()->create(['name' => 'Nakuru']);
        $naivasha = Location::factory()->create(['name' => 'Naivasha']);
        $mombasa = Location::factory()->create(['name' => 'Mombasa']);

        $based = $this->professional($nakuru);

        $covering = $this->professional($mombasa);
        $covering->serviceAreas()->attach($naivasha->id);

        $elsewhere = $this->professional($mombasa);

        $ids = $this->getJson("/api/professionals?location_id={$nakuru->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($based->id, $ids);
        $this->assertNotContains($covering->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);

        $naivashaIds = $this->getJson("/api/professionals?location_id={$naivasha->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($covering->id, $naivashaIds);
        $this->assertNotContains($based->id, $naivashaIds);
    }

    public function test_the_distance_filter_excludes_professionals_outside_the_radius(): void
    {
        $nakuru = Location::factory()->create(['latitude' => -0.3031, 'longitude' => 36.0800]);
        $mombasa = Location::factory()->create(['latitude' => -4.0435, 'longitude' => 39.6682]);

        $near = $this->professional($nakuru);
        $this->professional($mombasa);

        $this->getJson("/api/professionals?location_id={$nakuru->id}&max_distance_km=50")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $near->id)

            // Shown even though no radius was asked for; the origin is known.
            ->assertJsonPath('data.0.distance_km', 0.0);
    }

    public function test_a_rating_floor_excludes_the_less_regarded(): void
    {
        $base = Location::factory()->create();

        $strong = $this->professional($base, null, ['rating_avg' => 4.7, 'rating_count' => 40]);
        $this->professional($base, null, ['rating_avg' => 3.1, 'rating_count' => 12]);

        $this->getJson('/api/professionals?min_rating=4')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $strong->id);
    }

    public function test_the_price_bracket_keeps_only_professionals_starting_inside_it(): void
    {
        $base = Location::factory()->create();

        $cheap = $this->professional($base);
        ProfessionalService::factory()->forProfile($cheap)->fixed(150000)->create();

        $dear = $this->professional($base);
        ProfessionalService::factory()->forProfile($dear)->fixed(1500000)->create();

        // Quote-only: no starting price to compare, so it cannot be shown to fit
        // the bracket.
        $quoteOnly = $this->professional($base);
        ProfessionalService::factory()->forProfile($quoteOnly)->create();

        $ids = $this->getJson('/api/professionals?min_price_cents=100000&max_price_cents=500000')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($cheap->id, $ids);
        $this->assertNotContains($dear->id, $ids);
        $this->assertNotContains($quoteOnly->id, $ids);
    }

    public function test_availability_matches_the_weekly_pattern_and_respects_blocked_dates(): void
    {
        $base = Location::factory()->create();

        $date = now()->addDays(3);
        $otherDay = now()->addDays(4);

        $working = $this->professional($base);
        Availability::factory()->forProfile($working)->onDay($date->dayOfWeek)->create();

        // Works a different day.
        $offDuty = $this->professional($base);
        Availability::factory()->forProfile($offDuty)->onDay($otherDay->dayOfWeek)->create();

        // Usually works that day but has blocked it.
        $blocked = $this->professional($base);
        Availability::factory()->forProfile($blocked)->onDay($date->dayOfWeek)->create();
        AvailabilityException::factory()->forProfile($blocked)->on($date->toDateString())->create();

        // Never filled the grid in at all: absence of a row means unavailable.
        $unconfigured = $this->professional($base);

        $ids = $this->getJson('/api/professionals?date='.$date->toDateString())
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($working->id, $ids);
        $this->assertNotContains($offDuty->id, $ids);
        $this->assertNotContains($blocked->id, $ids);
        $this->assertNotContains($unconfigured->id, $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | Sorting and pagination
    |--------------------------------------------------------------------------
    */

    public function test_sorting_by_distance_puts_the_nearest_first(): void
    {
        $nakuru = Location::factory()->create(['latitude' => -0.3031, 'longitude' => 36.0800]);
        $naivasha = Location::factory()->create(['latitude' => -0.7167, 'longitude' => 36.4333]);
        $mombasa = Location::factory()->create(['latitude' => -4.0435, 'longitude' => 39.6682]);

        // Created nearest-last, so a default ordering would not accidentally
        // produce the expected result.
        $far = $this->professional($mombasa);
        $middle = $this->professional($naivasha);
        $near = $this->professional($nakuru);

        $this->getJson("/api/professionals?location_id={$nakuru->id}&sort=distance")
            ->assertOk()
            ->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $far->id);
    }

    public function test_sorting_by_distance_requires_a_location_to_measure_from(): void
    {
        $this->getJson('/api/professionals?sort=distance')
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_a_radius_requires_a_location_to_measure_from(): void
    {
        $this->getJson('/api/professionals?max_distance_km=25')
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_results_are_paginated_rather_than_sent_whole(): void
    {
        $base = Location::factory()->create();

        ProfessionalProfile::factory()->verified()->basedIn($base)->count(5)->create();

        $this->getJson('/api/professionals?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * Two professionals with identical sort keys must not swap places between
     * pages, or a customer paging through sees one twice and misses another.
     */
    public function test_pagination_is_stable_across_pages(): void
    {
        $base = Location::factory()->create();

        ProfessionalProfile::factory()->verified()->basedIn($base)->count(4)->create([
            'rating_avg' => 4.5,
            'rating_count' => 10,
        ]);

        $first = $this->getJson('/api/professionals?per_page=2&page=1')->json('data.*.id');
        $second = $this->getJson('/api/professionals?per_page=2&page=2')->json('data.*.id');

        $this->assertCount(4, array_unique([...$first, ...$second]));
    }

    public function test_the_search_term_matches_the_business_name(): void
    {
        $base = Location::factory()->create();

        $found = $this->professional($base, null, ['business_name' => 'Otieno Plumbing Services']);
        $this->professional($base, null, ['business_name' => 'Kariuki Electrical']);

        $this->getJson('/api/professionals?q=Otieno')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $found->id);
    }

    /**
     * A customer typing a trade into the search box means "who does this", not
     * "whose business is called this" — so the term reaches the service names a
     * professional offers, not only their own name.
     */
    public function test_the_search_term_matches_a_service_they_offer(): void
    {
        $base = Location::factory()->create();
        $plumbing = Service::factory()->create(['name' => 'Leak repair']);

        $found = $this->professional($base, $plumbing, ['business_name' => 'Mwikali Home Care']);
        $this->professional($base, null, ['business_name' => 'Njoroge Builders']);

        $this->getJson('/api/professionals?q=Leak')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $found->id);
    }

    /**
     * The service match runs through active offerings only. A professional who
     * has taken a service off their list should stop appearing for it.
     */
    public function test_the_search_term_ignores_a_service_they_have_switched_off(): void
    {
        $base = Location::factory()->create();
        $service = Service::factory()->create(['name' => 'Roof leaking repair']);

        $profile = $this->professional($base, $service, ['business_name' => 'Omondi Construction']);

        $profile->services()->update(['is_active' => false]);

        $this->getJson('/api/professionals?q=Roof')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * The card carries enough to choose between results: a teaser, a rating, a
     * starting price and the three services it is based on.
     */
    public function test_a_result_card_carries_the_choosing_details(): void
    {
        $base = Location::factory()->create();

        $profile = $this->professional($base, null, [
            'about' => str_repeat('Reliable plumbing across the county. ', 20),
            'experience_years' => 12,
        ]);

        foreach (Service::factory()->count(5)->create() as $index => $service) {
            ProfessionalService::factory()->forProfile($profile)->forService($service)
                ->fixed(100000 + $index)->create();
        }

        $response = $this->getJson('/api/professionals')->assertOk();

        $response->assertJsonPath('data.0.experience_years', 12)
            ->assertJsonPath('data.0.services_count', 5)
            ->assertJsonPath('data.0.from_price_cents', 100000);

        // Capped, so a professional with fifty services does not send fifty
        // names to every browser.
        $this->assertCount(3, $response->json('data.0.top_services'));
    }
}
