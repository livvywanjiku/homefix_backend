<?php

namespace Tests\Feature\Catalogue;

use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_list_active_categories_in_display_order(): void
    {
        // Created out of order so the assertion proves the ordering, rather
        // than passing because insertion order happened to match.
        ServiceCategory::factory()->create(['name' => 'Plumbing', 'slug' => 'plumbing', 'sort_order' => 2]);
        ServiceCategory::factory()->create(['name' => 'Electrical', 'slug' => 'electrical', 'sort_order' => 1]);

        $response = $this->getJson('/api/service-categories');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'electrical')
            ->assertJsonPath('data.1.slug', 'plumbing')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'icon', 'services_count']]]);
    }

    /**
     * A deactivated category must disappear from the public grid, not merely
     * render greyed out.
     */
    public function test_inactive_categories_are_hidden_from_the_public_list(): void
    {
        ServiceCategory::factory()->create(['slug' => 'visible']);
        ServiceCategory::factory()->inactive()->create(['slug' => 'hidden']);

        $response = $this->getJson('/api/service-categories');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('visible', $response->json('data.0.slug'));
    }

    public function test_a_category_lists_its_active_services_only(): void
    {
        $category = ServiceCategory::factory()->create(['slug' => 'plumbing']);

        Service::factory()->inCategory($category)->create(['name' => 'Leak Repair', 'slug' => 'leak-repair']);
        Service::factory()->inCategory($category)->inactive()->create(['name' => 'Retired Service', 'slug' => 'retired']);

        $response = $this->getJson('/api/service-categories/plumbing');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'plumbing')
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.name', 'Leak Repair');
    }

    public function test_an_unknown_category_slug_is_not_found(): void
    {
        $this->getJson('/api/service-categories/does-not-exist')->assertNotFound();
    }

    public function test_services_can_be_filtered_by_category(): void
    {
        $plumbing = ServiceCategory::factory()->create(['slug' => 'plumbing']);
        $electrical = ServiceCategory::factory()->create(['slug' => 'electrical']);

        Service::factory()->inCategory($plumbing)->create(['name' => 'Drain Cleaning']);
        Service::factory()->inCategory($electrical)->create(['name' => 'Wiring Repair']);

        $response = $this->getJson('/api/services?category=plumbing');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Drain Cleaning');
    }

    /**
     * Search has to be case-insensitive, because a customer typing "leak" must
     * find "Leak Repair" — and SQLite and PostgreSQL disagree about LIKE's
     * case behaviour by default, so the query cannot rely on either.
     */
    public function test_service_search_is_case_insensitive(): void
    {
        Service::factory()->create(['name' => 'Leak Repair']);

        $response = $this->getJson('/api/services?q=leak');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_inactive_services_are_hidden_from_the_service_list(): void
    {
        Service::factory()->create(['name' => 'Active Service']);
        Service::factory()->inactive()->create(['name' => 'Hidden Service']);

        $response = $this->getJson('/api/services');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_visitor_can_list_locations_with_a_display_label(): void
    {
        Location::factory()->create(['name' => 'Nakuru', 'region' => 'Nakuru County']);

        $response = $this->getJson('/api/locations');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Nakuru')
            ->assertJsonPath('data.0.label', 'Nakuru, Nakuru County');
    }

    public function test_inactive_locations_are_hidden(): void
    {
        Location::factory()->create(['name' => 'Active Town']);
        Location::factory()->inactive()->create(['name' => 'Closed Town']);

        $this->getJson('/api/locations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_locations_can_be_searched_case_insensitively(): void
    {
        Location::factory()->create(['name' => 'Naivasha']);
        Location::factory()->create(['name' => 'Mombasa']);

        $this->getJson('/api/locations?q=naiv')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Naivasha');
    }

    /**
     * Distance is computed in PHP so it works identically on the SQLite test
     * database, which has no trigonometric functions.
     */
    public function test_distance_between_two_locations_is_plausible(): void
    {
        $nakuru = Location::factory()->create(['latitude' => -0.3031, 'longitude' => 36.0800]);
        $naivasha = Location::factory()->create(['latitude' => -0.7167, 'longitude' => 36.4333]);

        $distance = $nakuru->distanceFrom($naivasha->latitude, $naivasha->longitude);

        // Roughly 57km apart in reality; the assertion is a tolerance band
        // rather than an exact figure, since the formula models a sphere.
        $this->assertNotNull($distance);
        $this->assertGreaterThan(45, $distance);
        $this->assertLessThan(70, $distance);
    }

    public function test_distance_is_null_when_a_location_has_no_coordinates(): void
    {
        $ungeocoded = Location::factory()->ungeocoded()->create();

        $this->assertNull($ungeocoded->distanceFrom(-0.3031, 36.0800));
    }

    public function test_the_catalogue_seeder_populates_categories_services_and_locations(): void
    {
        $this->seed(CatalogueSeeder::class);

        $this->assertSame(15, ServiceCategory::count());
        $this->assertSame(15, Location::count());
        $this->assertGreaterThan(50, Service::count());

        // Spot-check the spec's worked example.
        $this->assertDatabaseHas('service_categories', ['slug' => 'plumbing']);
        $this->assertDatabaseHas('services', ['slug' => 'leak-repair']);
        $this->assertDatabaseHas('locations', ['name' => 'Nakuru']);
    }

    /**
     * Re-running the seeder must not duplicate rows — an operator will re-run it
     * after editing the lists.
     */
    public function test_the_catalogue_seeder_is_idempotent(): void
    {
        $this->seed(CatalogueSeeder::class);
        $this->seed(CatalogueSeeder::class);

        $this->assertSame(15, ServiceCategory::count());
        $this->assertSame(15, Location::count());
    }
}
