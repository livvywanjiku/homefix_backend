<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAction;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The console's operational screens (spec §20): the service catalogue, the
 * booking state machine and the reporting interface.
 *
 * The booking tests are the important ones. An administrator arbitrating a
 * dispute is deliberately *not* exempt from the rules that define the dispute —
 * they go through the same `BookingService` guard as everybody else — so the
 * illegal move is refused here exactly as it is refused to a professional.
 */
class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_the_operations_screens_are_closed_to_a_guest(): void
    {
        foreach (['bookings', 'reports', 'categories', 'services'] as $path) {
            $this->getJson("/api/admin/{$path}")->assertUnauthorized();
        }
    }

    public function test_the_operations_screens_are_closed_to_a_professional(): void
    {
        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->getJson('/api/admin/bookings')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The service catalogue
    |--------------------------------------------------------------------------
    */

    public function test_a_category_can_be_added(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/categories', ['name' => 'Appliance Repair'])
            ->assertCreated()
            ->assertJsonPath('category.slug', 'appliance-repair');

        // The catalogue is what the public site shows, so an unexplained change
        // to it is indistinguishable from a defacement.
        $this->assertDatabaseHas('admin_logs', ['action' => AdminAction::CategoryCreated->value]);
    }

    public function test_a_service_can_be_added_to_a_category(): void
    {
        $category = ServiceCategory::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/services', [
                'service_category_id' => $category->id,
                'name' => 'Washing machine repair',
            ])
            ->assertCreated()
            ->assertJsonPath('service.slug', 'washing-machine-repair')
            ->assertJsonPath('service.category.id', $category->id);

        $this->assertDatabaseHas('admin_logs', ['action' => AdminAction::ServiceCreated->value]);
    }

    /**
     * `services.service_category_id` cascades on delete, so removing a category
     * would silently take its whole catalogue with it — including services
     * professionals are actively pricing against.
     */
    public function test_a_category_still_carrying_services_cannot_be_deleted(): void
    {
        $category = ServiceCategory::factory()->create();
        Service::factory()->create(['service_category_id' => $category->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = ServiceCategory::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    }

    /**
     * Offerings and historical requests both point at the service row, so a
     * service with any history is deactivated rather than removed.
     */
    public function test_a_service_a_professional_offers_cannot_be_deleted(): void
    {
        $service = Service::factory()->create();
        ProfessionalService::factory()->forService($service)->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/services/{$service->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | The booking state machine
    |--------------------------------------------------------------------------
    */

    public function test_an_illegal_transition_is_refused_however_it_is_asked_for(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Pending]);

        // The requirement is that invalid transitions are prevented, not that
        // the button is hidden. An administrator sending this by hand gets the
        // same answer as the client that would never have offered it.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/bookings/{$booking->id}/transition", [
                'status' => BookingStatus::Completed->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
        $this->assertDatabaseCount('booking_status_history', 0);
        $this->assertNull($booking->completed_at);
    }

    public function test_a_legal_transition_moves_the_booking_and_records_who_did_it(): void
    {
        $admin = $this->admin();
        $booking = Booking::factory()->create(['status' => BookingStatus::Scheduled]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/bookings/{$booking->id}/transition", [
                'status' => BookingStatus::InProgress->value,
                'note' => 'Dispute resolved in the customer’s favour.',
            ])
            ->assertOk();

        $booking->refresh();

        $this->assertSame(BookingStatus::InProgress, $booking->status);
        $this->assertNotNull($booking->started_at);

        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $booking->id,
            'from_status' => BookingStatus::Scheduled->value,
            'to_status' => BookingStatus::InProgress->value,
            'changed_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'action' => AdminAction::BookingUpdated->value,
            'subject_id' => $booking->id,
        ]);
    }

    public function test_completing_a_booking_credits_the_professional_exactly_once(): void
    {
        $professional = User::factory()->professional()->create();
        $profile = ProfessionalProfile::factory()->forUser($professional)->create();

        $booking = Booking::factory()
            ->forProfessional($professional)
            ->create(['status' => BookingStatus::InProgress]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/bookings/{$booking->id}/transition", [
                'status' => BookingStatus::Completed->value,
            ])
            ->assertOk();

        // The denormalised counter that ranks professionals is incremented in
        // the same transaction as the move, so the two cannot disagree.
        $this->assertSame(1, $profile->refresh()->completed_jobs_count);
        $this->assertNotNull($booking->refresh()->completed_at);
    }

    public function test_repeating_the_state_a_booking_is_already_in_changes_nothing(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::InProgress]);

        // A double-tapped "start work" should not produce a 422 or a second
        // history row.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/bookings/{$booking->id}/transition", [
                'status' => BookingStatus::InProgress->value,
            ])
            ->assertOk();

        $this->assertDatabaseCount('booking_status_history', 0);
    }

    public function test_the_console_is_told_which_moves_are_legal(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Scheduled]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/bookings/{$booking->id}/transitions")
            ->assertOk();

        $this->assertSame(BookingStatus::Scheduled->value, $response->json('current'));
        $this->assertEqualsCanonicalizing(
            ['on_the_way', 'in_progress', 'cancelled', 'disputed'],
            array_column($response->json('allowed'), 'value'),
        );
    }

    public function test_a_booking_carries_its_full_history_for_a_dispute(): void
    {
        $admin = $this->admin();
        $booking = Booking::factory()->create(['status' => BookingStatus::Accepted]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/bookings/{$booking->id}/transition", [
                'status' => BookingStatus::Scheduled->value,
                'note' => 'Scheduled after a call with both parties.',
            ])
            ->assertOk();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/bookings/{$booking->id}")
            ->assertOk();

        // The status column alone cannot answer "who cancelled this".
        $this->assertCount(1, $response->json('history'));
        $this->assertSame(BookingStatus::Accepted->value, $response->json('history.0.from'));
        $this->assertSame(BookingStatus::Scheduled->value, $response->json('history.0.to'));
        $this->assertSame($admin->name, $response->json('history.0.changed_by'));
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    public function test_the_reporting_interface_offers_only_reports_it_can_run(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/reports')
            ->assertOk();

        $this->assertSame(
            ['bookings', 'service_requests', 'users', 'professionals'],
            array_column($response->json('reports'), 'key'),
        );

        $this->assertSame(['csv', 'print'], $response->json('formats'));

        foreach ($response->json('reports') as $report) {
            $this->assertTrue($report['available'], "{$report['key']} should be available.");
            $this->assertNotEmpty($report['filters']);
        }
    }

    public function test_a_report_previews_rows_read_from_the_live_tables(): void
    {
        $booking = Booking::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/reports/bookings')
            ->assertOk();

        $this->assertNotEmpty($response->json('columns'));

        // The row is the booking that exists, not a fixture.
        $rows = collect($response->json('rows'))->keyBy('reference');

        $this->assertTrue($rows->has($booking->reference));
        $this->assertFalse($response->json('truncated'));
    }

    public function test_a_report_can_be_narrowed_to_one_status(): void
    {
        Booking::factory()->create(['status' => BookingStatus::Scheduled]);
        Booking::factory()->count(2)->create(['status' => BookingStatus::Cancelled]);

        $rows = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/reports/bookings?status='.BookingStatus::Cancelled->value)
            ->assertOk()
            ->json('rows');

        $this->assertCount(2, $rows);

        // Statuses are rendered as their display labels: a report is read by a
        // person, and `cancelled` is a database value.
        foreach ($rows as $row) {
            $this->assertSame(BookingStatus::Cancelled->label(), $row['status']);
        }
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/reports/invented')
            ->assertNotFound();
    }

    public function test_the_csv_export_streams_the_columns_it_advertises(): void
    {
        User::factory()->customer()->create(['name' => 'Wanjiku Mwangi']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/admin/reports/users/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Name,Email,Phone,Role,Status,Registered', $csv);
        $this->assertStringContainsString('Wanjiku Mwangi', $csv);
    }

    /**
     * The CSV is reachable two ways — the export route and `?format=csv` on the
     * preview route — and only one of them used to be gated on `export_reports`.
     * Reading a report and taking the whole dataset out of the building are
     * different privileges, so both doors have to be locked.
     */
    public function test_the_csv_format_is_refused_without_the_export_permission(): void
    {
        $admin = $this->admin();

        // Taken off the role rather than off the user: `revokePermissionTo()`
        // on a user removes only a direct grant, and this permission arrives
        // through the role, so revoking it there would silently leave the
        // administrator holding it and the test asserting nothing.
        Role::findByName(UserRole::Admin->value)
            ->revokePermissionTo(Permission::ExportReports->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/reports/users?format=csv')
            ->assertForbidden();

        // Neither the preview nor the print view is the export, so both stay
        // open — the point is to close the download, not the report.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/reports/users')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/reports/users?format=print')
            ->assertOk();
    }

    /**
     * No PDF library is installed, so "Export PDF" is the browser's own print
     * dialog over a print-styled document rather than a button that fails.
     */
    public function test_the_print_view_is_a_document_a_browser_can_save_as_a_pdf(): void
    {
        Booking::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->get('/api/admin/reports/bookings?format=print');

        $response->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('window.print()', $response->getContent());
    }

    public function test_the_reporting_interface_is_closed_to_a_customer(): void
    {
        $this->actingAs(User::factory()->customer()->create(), 'sanctum')
            ->getJson('/api/admin/reports')
            ->assertForbidden();
    }
}
