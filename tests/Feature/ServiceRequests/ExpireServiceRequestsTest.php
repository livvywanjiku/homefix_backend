<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The expiry sweep (spec §8).
 *
 * A request's deadline is already enforced on read — `hasExpired()` makes a
 * lapsed one unquotable and uneditable whether or not this has run. What the
 * sweep adds is the stored status, so the list a customer reads agrees with the
 * behaviour they get. The tests below are mostly about what it must *not* touch.
 */
class ExpireServiceRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_retires_a_lapsed_request(): void
    {
        $lapsed = ServiceRequest::factory()->expired()->create();

        $this->artisan('service-requests:expire')->assertSuccessful();

        $this->assertSame(ServiceRequestStatus::Expired, $lapsed->fresh()->status);
    }

    public function test_it_leaves_a_request_that_has_not_lapsed_alone(): void
    {
        $live = ServiceRequest::factory()->create(['expires_at' => now()->addDay()]);

        $this->artisan('service-requests:expire')->assertSuccessful();

        $this->assertSame(ServiceRequestStatus::Open, $live->fresh()->status);
    }

    /**
     * The load-bearing case. An awarded job whose deadline has passed has still
     * been agreed with a professional — expiring it would erase a commitment
     * that a booking is already standing on.
     */
    public function test_it_does_not_touch_a_request_that_has_reached_a_terminal_state(): void
    {
        $awarded = ServiceRequest::factory()
            ->awarded()
            ->create(['expires_at' => now()->subDay()]);

        $cancelled = ServiceRequest::factory()
            ->cancelled()
            ->create(['expires_at' => now()->subDay()]);

        $this->artisan('service-requests:expire')->assertSuccessful();

        $this->assertSame(ServiceRequestStatus::Awarded, $awarded->fresh()->status);
        $this->assertSame(ServiceRequestStatus::Cancelled, $cancelled->fresh()->status);
    }

    /** A quoted request is still live until its deadline, and lapses like any other. */
    public function test_it_retires_a_lapsed_request_that_was_quoted(): void
    {
        $quoted = ServiceRequest::factory()
            ->quoted()
            ->create(['expires_at' => now()->subMinute()]);

        $this->artisan('service-requests:expire')->assertSuccessful();

        $this->assertSame(ServiceRequestStatus::Expired, $quoted->fresh()->status);
    }

    public function test_it_reports_when_there_was_nothing_to_do(): void
    {
        $this->artisan('service-requests:expire')
            ->expectsOutputToContain('No service requests had lapsed.')
            ->assertSuccessful();
    }
}
