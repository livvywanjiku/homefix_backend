<?php

namespace Tests\Feature\Quotations;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing a job, and awarding it (spec §9–§10).
 *
 * The rule that shapes most of this file: a customer never names a price and a
 * professional never awards a job. Everything a client sends is either a figure
 * the professional is genuinely offering, or a decision the customer is
 * genuinely entitled to make — and every derived number is computed here.
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A verified professional. Only verified trades may price work, so this is
     * the ordinary case rather than a special one.
     */
    private function professional(): User
    {
        $user = User::factory()->professional()->create();

        ProfessionalProfile::factory()->verified()->forUser($user)->create();

        return $user;
    }

    private function customer(): User
    {
        return User::factory()->customer()->create();
    }

    /**
     * An open job, in a town and against a service, owned by the given customer.
     */
    private function job(User $customer, array $attributes = []): ServiceRequest
    {
        return ServiceRequest::factory()
            ->forCustomer($customer)
            ->forService(Service::factory()->create())
            ->inLocation(Location::factory()->create())
            ->create($attributes);
    }

    /**
     * A realistic itemised price: two days of labour, three bags of cement and a
     * van. Totals 465,000 cents.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'message' => 'Includes a two-year guarantee on the parts.',
            'estimated_days' => 3,
            'items' => [
                ['category' => 'labour', 'description' => 'Two days on site', 'quantity' => 2, 'unit_price_cents' => 150000],
                ['category' => 'materials', 'description' => 'Cement', 'quantity' => 3, 'unit_price_cents' => 45000],
                ['category' => 'transport', 'description' => 'Van and fuel', 'quantity' => 1, 'unit_price_cents' => 30000],
            ],
        ], $overrides);
    }

    /**
     * Submits a price through the API and returns the stored quotation.
     */
    private function submit(User $professional, ServiceRequest $job, array $overrides = []): Quotation
    {
        $response = $this->actingAs($professional, 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload($overrides),
            ])
            ->assertCreated();

        return Quotation::findOrFail($response->json('data.id'));
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting a price
    |--------------------------------------------------------------------------
    */

    public function test_a_verified_professional_can_price_an_open_job(): void
    {
        $job = $this->job($this->customer());
        $professional = $this->professional();

        $this->actingAs($professional, 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', QuotationStatus::Pending->value)
            ->assertJsonPath('data.professional.id', $professional->id)
            ->assertJsonCount(3, 'data.items');
    }

    /**
     * The breakdown is the source of truth and the four category totals are its
     * sums. A part-unit quantity has to round to the nearest cent rather than
     * truncate, or a breakdown priced in metres quietly loses money on every line.
     */
    public function test_the_totals_are_the_sums_of_the_lines(): void
    {
        $job = $this->job($this->customer());

        $response = $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
            ])
            ->assertCreated();

        // 2 x 150000 + 3 x 45000 + 1 x 30000.
        $response->assertJsonPath('data.labour_cents', 300000)
            ->assertJsonPath('data.materials_cents', 135000)
            ->assertJsonPath('data.transport_cents', 30000)
            ->assertJsonPath('data.other_cents', 0)
            ->assertJsonPath('data.total_cents', 465000);
    }

    public function test_a_part_unit_quantity_is_rounded_to_the_nearest_cent(): void
    {
        $job = $this->job($this->customer());

        // 1.5 x 33333 = 49999.5, which must land on 50000 rather than 49999.
        $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                'items' => [
                    ['category' => 'materials', 'description' => 'Cable', 'quantity' => 1.5, 'unit_price_cents' => 33333],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_cents', 50000)
            ->assertJsonPath('data.items.0.quantity', 1.5);
    }

    /**
     * The whole point of deriving the totals server-side. A client can name its
     * own unit prices — that is what quoting is — but it cannot name the figure
     * the platform will compare, sort and later bill against.
     */
    public function test_a_client_cannot_dictate_the_totals(): void
    {
        $job = $this->job($this->customer());

        $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
                'total_cents' => 1,
                'labour_cents' => 1,
                'status' => QuotationStatus::Accepted->value,
                'reference' => 'QUO-000001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_cents', 465000)
            ->assertJsonPath('data.labour_cents', 300000)
            ->assertJsonPath('data.status', QuotationStatus::Pending->value);

        $this->assertDatabaseMissing('quotations', ['reference' => 'QUO-000001']);
    }

    public function test_pricing_a_job_moves_it_to_quotes_received_and_counts_the_offer(): void
    {
        $job = $this->job($this->customer());

        $this->submit($this->professional(), $job);

        $job->refresh();

        $this->assertSame(ServiceRequestStatus::Quoted, $job->status);
        $this->assertSame(1, $job->quotes_count);
    }

    /**
     * A second professional quoting does not re-move the request or overwrite the
     * count — it adds to it.
     */
    public function test_a_second_offer_adds_to_the_count_without_changing_the_status(): void
    {
        $job = $this->job($this->customer());

        $this->submit($this->professional(), $job);
        $this->submit($this->professional(), $job);

        $job->refresh();

        $this->assertSame(ServiceRequestStatus::Quoted, $job->status);
        $this->assertSame(2, $job->quotes_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may quote, and on what
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_cannot_price_work(): void
    {
        $job = $this->job($this->customer());

        $this->postJson('/api/professional/quotations', [
            'service_request_id' => $job->id,
            ...$this->payload(),
        ])->assertUnauthorized();
    }

    public function test_a_customer_cannot_reach_the_professional_quoting_endpoints(): void
    {
        $job = $this->job($this->customer());

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
            ])
            ->assertForbidden();
    }

    /**
     * The platform's promise is verified trades. An applicant who has not been
     * vetted must not be able to price work, even though they can browse the board.
     */
    public function test_an_unverified_professional_cannot_price_work(): void
    {
        $job = $this->job($this->customer());

        $applicant = User::factory()->professional()->create();
        ProfessionalProfile::factory()->forUser($applicant)->create();

        $this->actingAs($applicant, 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
            ])
            ->assertForbidden();
    }

    /**
     * The board only ever shows open work, but the board is not the gate. Three
     * ways a job stops being priceable, each with its own reason: somebody else
     * won it, the customer gave up on it, or it simply lapsed.
     */
    public function test_a_professional_cannot_price_a_job_that_is_no_longer_open(): void
    {
        $professional = $this->professional();
        $customer = $this->customer();

        $closed = [
            'an awarded' => ['status' => ServiceRequestStatus::Awarded],
            'a cancelled' => ['status' => ServiceRequestStatus::Cancelled],
            'a lapsed' => ['expires_at' => now()->subDay()],
        ];

        foreach ($closed as $reason => $attributes) {
            $job = $this->job($customer, $attributes);

            $this->actingAs($professional, 'sanctum')
                ->postJson('/api/professional/quotations', [
                    'service_request_id' => $job->id,
                    ...$this->payload(),
                ])
                ->assertForbidden("A professional priced {$reason} job.");
        }

        $this->assertSame(0, Quotation::query()->count());
    }

    /**
     * One price per professional per job, enforced by a unique index rather than
     * by a check that a double submit could slip past.
     */
    public function test_a_professional_cannot_price_the_same_job_twice(): void
    {
        $job = $this->job($this->customer());
        $professional = $this->professional();

        $this->submit($professional, $job);

        $this->actingAs($professional, 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(),
            ])
            ->assertStatus(422);

        $this->assertSame(1, $job->refresh()->quotes_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Revising and withdrawing
    |--------------------------------------------------------------------------
    */

    public function test_a_professional_can_revise_a_live_offer_and_the_totals_follow(): void
    {
        $professional = $this->professional();
        $quotation = $this->submit($professional, $this->job($this->customer()));

        $this->actingAs($professional, 'sanctum')
            ->putJson("/api/professional/quotations/{$quotation->id}", [
                'message' => 'Revised after a second look.',
                'items' => [
                    ['category' => 'labour', 'description' => 'One day instead of two', 'quantity' => 1, 'unit_price_cents' => 150000],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.total_cents', 150000)
            ->assertJsonPath('data.labour_cents', 150000)
            // The categories that lost their lines must fall back to zero rather
            // than keep the figures the old breakdown contributed.
            ->assertJsonPath('data.materials_cents', 0)
            ->assertJsonPath('data.transport_cents', 0)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_a_professional_can_withdraw_a_live_offer(): void
    {
        $professional = $this->professional();
        $quotation = $this->submit($professional, $this->job($this->customer()));

        $this->actingAs($professional, 'sanctum')
            ->postJson("/api/professional/quotations/{$quotation->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', QuotationStatus::Withdrawn->value);
    }

    public function test_a_withdrawn_offer_cannot_be_revised(): void
    {
        $professional = $this->professional();
        $quotation = $this->submit($professional, $this->job($this->customer()));

        $this->actingAs($professional, 'sanctum')
            ->postJson("/api/professional/quotations/{$quotation->id}/withdraw")
            ->assertOk();

        $this->actingAs($professional, 'sanctum')
            ->putJson("/api/professional/quotations/{$quotation->id}", [
                'items' => [
                    ['category' => 'labour', 'description' => 'Actually, less', 'quantity' => 1, 'unit_price_cents' => 5000],
                ],
            ])
            ->assertForbidden();
    }

    /**
     * Somebody else's offer answers 404 rather than 403, so this cannot be used
     * to watch a competitor's pipeline.
     */
    public function test_a_professional_cannot_touch_another_professionals_offer(): void
    {
        $quotation = $this->submit($this->professional(), $this->job($this->customer()));
        $rival = $this->professional();

        $this->actingAs($rival, 'sanctum')
            ->getJson("/api/professional/quotations/{$quotation->id}")
            ->assertNotFound();

        $this->actingAs($rival, 'sanctum')
            ->postJson("/api/professional/quotations/{$quotation->id}/withdraw")
            ->assertNotFound();
    }

    public function test_the_professional_list_holds_only_their_own_offers(): void
    {
        $job = $this->job($this->customer());
        $mine = $this->professional();
        $theirs = $this->professional();

        $this->submit($mine, $job);
        $this->submit($theirs, $job);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/professional/quotations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.professional.id', $mine->id);
    }

    public function test_the_professional_list_reports_what_became_of_each_offer(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);
        $professional = $this->professional();
        $quotation = $this->submit($professional, $job);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertOk();

        $this->actingAs($professional, 'sanctum')
            ->getJson('/api/professional/quotations')
            ->assertOk()
            ->assertJsonPath('data.0.status', QuotationStatus::Accepted->value)
            // The job itself moved on too, and the professional's own list is
            // where they find out.
            ->assertJsonPath('data.0.service_request.status', ServiceRequestStatus::Awarded->value);
    }

    /*
    |--------------------------------------------------------------------------
    | The customer's comparison table
    |--------------------------------------------------------------------------
    */

    public function test_the_customer_sees_every_offer_on_their_job(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $this->submit($this->professional(), $job, ['items' => [
            ['category' => 'labour', 'description' => 'Dearer', 'quantity' => 1, 'unit_price_cents' => 900000],
        ]]);
        $this->submit($this->professional(), $job, ['items' => [
            ['category' => 'labour', 'description' => 'Cheaper', 'quantity' => 1, 'unit_price_cents' => 400000],
        ]]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Cheapest first among equally-live offers: the customer wants the
            // answer, not the submission order.
            ->assertJsonPath('data.0.total_cents', 400000);
    }

    public function test_another_customer_cannot_read_the_offers_on_a_job(): void
    {
        $job = $this->job($this->customer());
        $this->submit($this->professional(), $job);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertForbidden();
    }

    /**
     * The request id and the quotation id must belong together. Without the
     * check, a customer could accept a quotation by pairing their own request
     * with somebody else's quotation and have the policy look at the wrong row.
     */
    public function test_a_mismatched_request_and_quotation_pair_is_not_found(): void
    {
        $customer = $this->customer();
        $mine = $this->job($customer);
        $theirs = $this->job($customer);

        $quotation = $this->submit($this->professional(), $theirs);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$mine->id}/quotations/{$quotation->id}/accept")
            ->assertNotFound();
    }

    public function test_a_customer_cannot_accept_an_offer_on_somebody_elses_job(): void
    {
        $job = $this->job($this->customer());
        $quotation = $this->submit($this->professional(), $job);

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertForbidden();
    }

    public function test_a_customer_cannot_accept_an_offer_on_their_own_behalf_as_a_professional(): void
    {
        $professional = $this->professional();
        $quotation = $this->submit($professional, $this->job($this->customer()));

        $this->actingAs($professional, 'sanctum')
            ->postJson("/api/service-requests/{$quotation->service_request_id}/quotations/{$quotation->id}/accept")
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Awarding the job
    |--------------------------------------------------------------------------
    */

    public function test_accepting_an_offer_awards_the_job_and_creates_the_booking(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);
        $professional = $this->professional();
        $quotation = $this->submit($professional, $job);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', QuotationStatus::Accepted->value)
            ->assertJsonPath('data.total_cents', 465000);

        $this->assertSame(ServiceRequestStatus::Awarded, $job->refresh()->status);

        $booking = Booking::query()->where('quotation_id', $quotation->id)->firstOrFail();

        $this->assertSame(BookingStatus::Accepted, $booking->status);
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame($professional->id, $booking->professional_id);
        $this->assertSame($job->id, $booking->service_request_id);

        // The terms are copied, not referenced: the booking has to keep standing
        // if the quotation behind it is ever edited.
        $this->assertSame(465000, $booking->total_cents);
        $this->assertSame(300000, $booking->labour_cents);
        $this->assertSame($job->title, $booking->title);
        $this->assertSame($job->address, $booking->address);
        $this->assertNotNull($booking->agreed_at);
    }

    public function test_accepting_one_offer_turns_down_every_other_live_one(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $chosen = $this->submit($this->professional(), $job);
        $rival = $this->submit($this->professional(), $job);
        $silent = $this->submit($this->professional(), $job);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$chosen->id}/accept")
            ->assertOk();

        $this->assertSame(QuotationStatus::Accepted, $chosen->refresh()->status);

        foreach ([$rival, $silent] as $other) {
            $this->assertSame(QuotationStatus::Rejected, $other->refresh()->status);
            $this->assertNotNull($other->rejected_at);
        }

        // Exactly one booking, and it belongs to the offer that won.
        $this->assertSame(1, Booking::query()->where('service_request_id', $job->id)->count());
    }

    public function test_an_offer_that_has_already_lost_cannot_be_accepted(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $winner = $this->submit($this->professional(), $job);
        $loser = $this->submit($this->professional(), $job);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$winner->id}/accept")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$loser->id}/accept")
            ->assertStatus(422);
    }

    /**
     * A price is an offer with a shelf life. Accepting a lapsed one would commit
     * a professional to last month's material costs.
     */
    public function test_a_lapsed_offer_cannot_be_accepted(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $quotation = Quotation::factory()
            ->forRequest($job)
            ->by($this->professional())
            ->priced(465000)
            ->validUntil(now()->subDay())
            ->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertStatus(422);

        $this->assertSame(QuotationStatus::Pending, $quotation->refresh()->status);
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_an_offer_on_a_cancelled_job_cannot_be_accepted(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $quotation = Quotation::factory()
            ->forRequest($job)
            ->by($this->professional())
            ->priced(465000)
            ->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/cancel")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertStatus(422);

        $this->assertSame(0, Booking::query()->count());
    }

    /**
     * Turning one offer down individually is not a decision to hire somebody
     * else, so the job stays on the board and the other offers stay live.
     */
    public function test_rejecting_one_offer_leaves_the_job_and_its_other_offers_alone(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);

        $unwanted = $this->submit($this->professional(), $job);
        $stillIn = $this->submit($this->professional(), $job);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$unwanted->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', QuotationStatus::Rejected->value);

        $this->assertSame(QuotationStatus::Pending, $stillIn->refresh()->status);
        $this->assertSame(ServiceRequestStatus::Quoted, $job->refresh()->status);
        $this->assertTrue($job->acceptsQuotations());
    }

    /**
     * Withdrawing is the author's act. A customer who no longer wants an offer
     * rejects it — which leaves a record of the decision — rather than pulling it
     * as though the professional had changed their mind.
     */
    public function test_a_customer_cannot_withdraw_a_professionals_offer(): void
    {
        $customer = $this->customer();
        $quotation = $this->submit($this->professional(), $this->job($customer));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/professional/quotations/{$quotation->id}/withdraw")
            ->assertForbidden();

        $this->assertSame(QuotationStatus::Pending, $quotation->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_a_price_with_no_breakdown_is_refused(): void
    {
        $job = $this->job($this->customer());

        $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                'items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_a_zero_priced_or_zero_quantity_line_is_refused(): void
    {
        $job = $this->job($this->customer());

        foreach ([
            ['category' => 'labour', 'description' => 'Free, apparently', 'quantity' => 1, 'unit_price_cents' => 0],
            ['category' => 'labour', 'description' => 'No units', 'quantity' => 0, 'unit_price_cents' => 5000],
        ] as $line) {
            $this->actingAs($this->professional(), 'sanctum')
                ->postJson('/api/professional/quotations', [
                    'service_request_id' => $job->id,
                    'items' => [$line],
                ])
                ->assertStatus(422);
        }
    }

    public function test_a_line_must_be_filed_under_a_real_cost_category(): void
    {
        $job = $this->job($this->customer());

        $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                'items' => [
                    ['category' => 'gratuity', 'description' => 'A tip', 'quantity' => 1, 'unit_price_cents' => 5000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.category');
    }

    public function test_a_price_that_expires_on_submission_is_refused(): void
    {
        $job = $this->job($this->customer());

        $this->actingAs($this->professional(), 'sanctum')
            ->postJson('/api/professional/quotations', [
                'service_request_id' => $job->id,
                ...$this->payload(['valid_until' => now()->toDateString()]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_until');
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry sweep
    |--------------------------------------------------------------------------
    */

    public function test_the_sweep_retires_lapsed_offers(): void
    {
        $quotation = Quotation::factory()
            ->forRequest($this->job($this->customer()))
            ->by($this->professional())
            ->priced()
            ->expired()
            ->create();

        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Expired, $quotation->refresh()->status);
    }

    public function test_the_sweep_leaves_a_live_offer_alone(): void
    {
        $quotation = Quotation::factory()
            ->forRequest($this->job($this->customer()))
            ->by($this->professional())
            ->priced()
            ->create();

        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Pending, $quotation->refresh()->status);
    }

    /**
     * A decision does not expire. Retiring an accepted offer would leave the
     * booking that came out of it with no live quotation behind it.
     */
    public function test_the_sweep_does_not_touch_offers_that_were_decided(): void
    {
        $accepted = Quotation::factory()
            ->forRequest($this->job($this->customer()))
            ->by($this->professional())
            ->priced()
            ->accepted()
            ->validUntil(now()->subDay())
            ->create();

        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Accepted, $accepted->refresh()->status);
    }

    public function test_the_sweep_says_so_when_there_was_nothing_to_do(): void
    {
        $this->artisan('quotations:expire')
            ->expectsOutputToContain('No quotations had lapsed.')
            ->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | Contact details
    |--------------------------------------------------------------------------
    |
    | The two parties only learn each other's phone number once a quote has
    | been accepted. Before that, discovery and comparison must stay free of
    | contact details, or the arrangement can leave the platform before it
    | exists.
    */

    public function test_a_pending_quotation_reveals_no_phone_number_to_either_side(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);
        $professional = $this->professional();

        $quotation = $this->submit($professional, $job);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertOk()
            ->assertJsonPath('data.0.contact', null);

        $this->actingAs($professional, 'sanctum')
            ->getJson("/api/professional/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.contact', null);
    }

    public function test_the_customer_sees_the_professionals_business_number_after_the_award(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);
        $professional = $this->professional();

        $profile = $professional->professionalProfile;
        $profile->update(['phone' => '+254722334455']);

        $quotation = Quotation::factory()
            ->forRequest($job)
            ->by($professional)
            ->priced()
            ->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/service-requests/{$job->id}/quotations/{$quotation->id}/accept")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'accepted')
            ->assertJsonPath('data.0.contact.phone', '+254722334455')
            ->assertJsonPath('data.0.contact.name', $profile->business_name);
    }

    public function test_the_customers_account_number_is_the_fallback_when_the_business_phone_is_blank(): void
    {
        $customer = $this->customer();
        $job = $this->job($customer);
        $professional = $this->professional();

        $professional->update(['phone' => '+254733556677']);
        $professional->professionalProfile->update(['phone' => null]);

        Quotation::factory()
            ->forRequest($job)
            ->by($professional)
            ->priced()
            ->accepted()
            ->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertOk()
            ->assertJsonPath('data.0.contact.phone', '+254733556677');
    }

    public function test_the_professional_sees_the_customers_number_after_the_award(): void
    {
        $customer = $this->customer();
        $customer->update(['phone' => '+254744667788']);

        $job = $this->job($customer);
        $professional = $this->professional();

        $quotation = Quotation::factory()
            ->forRequest($job)
            ->by($professional)
            ->priced()
            ->accepted()
            ->create();

        $this->actingAs($professional, 'sanctum')
            ->getJson("/api/professional/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.contact.phone', '+254744667788')
            ->assertJsonPath('data.contact.name', $customer->name);
    }

    public function test_another_professional_cannot_read_an_awarded_quotation_or_its_contact(): void
    {
        $job = $this->job($this->customer());

        $quotation = Quotation::factory()
            ->forRequest($job)
            ->by($this->professional())
            ->priced()
            ->accepted()
            ->create();

        $this->actingAs($this->professional(), 'sanctum')
            ->getJson("/api/professional/quotations/{$quotation->id}")
            ->assertNotFound();
    }

    public function test_another_customer_cannot_see_the_quotes_or_their_contact(): void
    {
        $job = $this->job($this->customer());

        Quotation::factory()
            ->forRequest($job)
            ->by($this->professional())
            ->priced()
            ->accepted()
            ->create();

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson("/api/service-requests/{$job->id}/quotations")
            ->assertForbidden();
    }
}
