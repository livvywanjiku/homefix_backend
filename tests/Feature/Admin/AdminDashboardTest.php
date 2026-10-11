<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VerificationStatus;
use App\Models\Booking;
use App\Models\ProfessionalProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The administration dashboard and its analytics endpoints (spec §20).
 *
 * Two things are being checked throughout. The first is authorisation: the
 * console is reached by a hand-rolled request as easily as by the browser, so
 * every gate is asserted from the outside rather than assumed from the frontend.
 * The second is honesty: a figure the platform cannot compute yet must be
 * reported as unavailable, never as a zero that reads like a fact.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function administrator(): User
    {
        return User::factory()->admin()->create();
    }

    private function superAdministrator(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_is_refused_the_dashboard(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_a_customer_is_refused_the_dashboard(): void
    {
        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_a_professional_is_refused_the_dashboard(): void
    {
        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    /*
     * A super administrator has to get in. The `role:` parameter is a pipe-list,
     * and a console that only answered to `admin` would lock its own owner out.
     */
    public function test_both_administrative_roles_may_reach_the_dashboard(): void
    {
        $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();

        $this->actingAs($this->superAdministrator(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | The summary
    |--------------------------------------------------------------------------
    */

    public function test_the_dashboard_reports_its_sections_and_the_period_they_cover(): void
    {
        $response = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/dashboard?range=last_7_days')
            ->assertOk();

        $response->assertJsonStructure([
            'range' => ['preset', 'from', 'to', 'granularity', 'days'],
            'presets',
            'stats' => [['key', 'label', 'value', 'previous', 'change_percent', 'trend', 'available']],
            'activity',
            'pending_actions',
            'system_health' => [['key', 'label', 'status', 'detail']],
            'system_health_overall',
            'availability' => ['payments', 'reviews', 'complaints'],
        ]);

        $this->assertSame('last_7_days', $response->json('range.preset'));
        $this->assertSame(7, $response->json('range.days'));
    }

    public function test_an_unknown_period_falls_back_rather_than_failing(): void
    {
        $response = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/dashboard?range=last_century')
            ->assertOk();

        $this->assertSame('last_30_days', $response->json('range.preset'));
    }

    /*
    |--------------------------------------------------------------------------
    | The figures are real
    |--------------------------------------------------------------------------
    */

    public function test_the_customer_count_is_the_number_of_customers_that_exist(): void
    {
        User::factory()->customer()->count(4)->create();
        User::factory()->professional()->count(3)->create();
        // An administrator is neither, and must not be counted as one.
        $this->administrator();

        $stats = collect(
            $this->actingAs($this->administrator(), 'sanctum')
                ->getJson('/api/admin/dashboard')
                ->assertOk()
                ->json('stats'),
        )->keyBy('key');

        $this->assertSame(4, $stats['total_customers']['value']);
        $this->assertSame(3, $stats['total_professionals']['value']);
        $this->assertSame(8, $stats['total_users']['value']);
    }

    public function test_a_growth_rate_is_withheld_when_there_is_nothing_to_compare_against(): void
    {
        // Every account was created inside the period, so the previous period
        // holds nobody. "+100%" from a base of zero is not a growth rate.
        User::factory()->customer()->count(3)->create();

        $stats = collect(
            $this->actingAs($this->administrator(), 'sanctum')
                ->getJson('/api/admin/dashboard?range=last_30_days')
                ->json('stats'),
        )->keyBy('key');

        $this->assertSame(3, $stats['total_customers']['value']);
        $this->assertSame(0, $stats['total_customers']['previous']);
        $this->assertNull($stats['total_customers']['change_percent']);
        $this->assertSame('up', $stats['total_customers']['trend']);
    }

    public function test_bookings_are_counted_by_the_state_they_are_actually_in(): void
    {
        $customer = User::factory()->customer()->create();
        $professional = User::factory()->professional()->create();

        Booking::factory()->count(2)->create([
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'status' => BookingStatus::InProgress,
        ]);

        Booking::factory()->create([
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'status' => BookingStatus::Completed,
        ]);

        $analytics = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/bookings/analytics')
            ->assertOk()
            ->json('bookings');

        $this->assertSame(3, $analytics['total']);

        $byStatus = collect($analytics['statuses'])->keyBy('value');

        $this->assertSame(2, $byStatus[BookingStatus::InProgress->value]['total']);
        $this->assertSame(1, $byStatus[BookingStatus::Completed->value]['total']);
        $this->assertSame(0, $byStatus[BookingStatus::Cancelled->value]['total']);
    }

    public function test_the_verification_breakdown_reflects_the_queues(): void
    {
        ProfessionalProfile::factory()->verified()->count(2)->create();
        ProfessionalProfile::factory()->count(3)->create();

        $professionals = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/professionals/analytics')
            ->assertOk()
            ->json('professionals');

        $byStatus = collect($professionals['verification'])->keyBy('value');

        $this->assertSame(2, $byStatus[VerificationStatus::Verified->value]['total']);
        $this->assertSame(3, $byStatus[VerificationStatus::Pending->value]['total']);
    }

    public function test_the_trend_series_covers_the_whole_period_including_quiet_buckets(): void
    {
        Booking::factory()->count(2)->create();

        $series = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/bookings/analytics?range=last_30_days')
            ->assertOk()
            ->json('bookings.series');

        // A point per day for a thirty-day window, not one point per row.
        $this->assertCount(30, $series);
        $this->assertSame(2, array_sum(array_column($series, 'value')));
    }

    /*
    |--------------------------------------------------------------------------
    | Features that do not exist yet say so
    |--------------------------------------------------------------------------
    */

    public function test_sections_backed_by_unbuilt_tables_report_themselves_unavailable(): void
    {
        $response = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();

        // The payments, reviews and complaints slices have not shipped, so their
        // tables do not exist. Reporting zero would claim nobody has ever paid.
        $this->assertFalse($response->json('availability.payments'));
        $this->assertFalse($response->json('availability.reviews'));
        $this->assertFalse($response->json('availability.complaints'));

        $stats = collect($response->json('stats'))->keyBy('key');
        $this->assertFalse($stats['platform_revenue']['available']);
        $this->assertNull($stats['platform_revenue']['value']);

        // And the live slices are correctly reported as live.
        $this->assertTrue($response->json('availability.bookings'));
        $this->assertTrue($response->json('availability.quotations'));
    }

    public function test_the_revenue_panel_reports_itself_unavailable_rather_than_empty(): void
    {
        // The dashboard summary deliberately omits revenue — it is a panel, not
        // a headline — so the assertion belongs against its own endpoint.
        $revenue = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/revenue')
            ->assertOk()
            ->json('revenue');

        $this->assertFalse($revenue['available']);
        $this->assertNull($revenue['totals']);
        $this->assertSame([], $revenue['series']);
        $this->assertNotEmpty($revenue['reason']);
    }

    public function test_an_unconfigured_integration_reports_not_configured_rather_than_operational(): void
    {
        config()->set('broadcasting.default', 'null');
        config()->set('queue.default', 'sync');

        $health = collect(
            $this->actingAs($this->administrator(), 'sanctum')
                ->getJson('/api/admin/dashboard')
                ->json('system_health'),
        )->keyBy('key');

        // A green tick against a service nobody has set up is worse than no
        // panel at all, because it is confidently wrong.
        $this->assertSame('not_configured', $health['realtime']['status']);
        $this->assertSame('not_configured', $health['queue']['status']);

        // And the verdict does not call the platform unwell because of them.
        $this->assertNotSame('down', $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->json('system_health_overall'));
    }

    /*
    |--------------------------------------------------------------------------
    | Pending work
    |--------------------------------------------------------------------------
    */

    public function test_pending_actions_point_at_the_work_waiting_on_a_decision(): void
    {
        ProfessionalProfile::factory()->count(2)->create();

        $actions = collect(
            $this->actingAs($this->administrator(), 'sanctum')
                ->getJson('/api/admin/pending-actions')
                ->assertOk()
                ->json('pending_actions'),
        )->keyBy('key');

        $this->assertSame(2, $actions['pending_verifications']['count']);
        $this->assertSame('/admin/verification', $actions['pending_verifications']['href']);
    }

    public function test_a_quiet_platform_shows_no_pending_actions_rather_than_empty_rows(): void
    {
        $this->assertSame([], $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/pending-actions')
            ->assertOk()
            ->json('pending_actions'));
    }

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    */

    public function test_the_timeline_is_newest_first_and_respects_its_limit(): void
    {
        User::factory()->customer()->count(5)->create();

        $activity = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/activity?limit=3')
            ->assertOk()
            ->json('activity');

        $this->assertCount(3, $activity);

        $timestamps = array_column($activity, 'at');
        $sorted = $timestamps;
        rsort($sorted);

        $this->assertSame($sorted, $timestamps);
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics endpoints
    |--------------------------------------------------------------------------
    */

    public function test_each_analytics_endpoint_answers_under_its_own_permission(): void
    {
        $admin = $this->administrator();

        foreach ([
            'revenue',
            'bookings/analytics',
            'users/analytics',
            'professionals/analytics',
            'services/analytics',
            'locations/analytics',
        ] as $path) {
            $this->actingAs($admin, 'sanctum')
                ->getJson("/api/admin/{$path}")
                ->assertOk();
        }

        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/admin/users/analytics')
            ->assertForbidden();
    }

    public function test_service_demand_is_measured_from_postings_rather_than_from_advertising(): void
    {
        $request = ServiceRequest::factory()->create();

        $services = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/services/analytics')
            ->assertOk()
            ->json('services');

        $this->assertSame($request->service->name, $services['most_requested'][0]['name']);
        $this->assertSame(1, $services['most_requested'][0]['total']);

        // Revenue is reported as unavailable, not as a zero.
        $this->assertFalse($services['revenue_available']);
        $this->assertNull($services['highest_revenue']);
    }

    public function test_the_service_request_list_can_be_narrowed_to_one_status(): void
    {
        ServiceRequest::factory()->count(2)->create();
        ServiceRequest::factory()->create(['status' => ServiceRequestStatus::Cancelled]);

        $response = $this->actingAs($this->administrator(), 'sanctum')
            ->getJson('/api/admin/service-requests?status=cancelled')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(
            ServiceRequestStatus::Cancelled->value,
            $response->json('data.0.status'),
        );
    }
}
