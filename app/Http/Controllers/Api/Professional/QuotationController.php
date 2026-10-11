<?php

namespace App\Http\Controllers\Api\Professional;

use App\Events\QuotationSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\ListQuotationsRequest;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use App\Http\Requests\Quotations\UpdateQuotationRequest;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A professional's own price offers (spec §9).
 *
 * No route here takes a professional id: the controller reads the account from
 * the token, so there is no id to tamper with and no ownership check that can be
 * forgotten. Where a quotation is addressed by id, authorship is confirmed before
 * anything is read or written.
 */
class QuotationController extends Controller
{
    /**
     * The offers this professional has made, across every job.
     *
     * Necessary because the board only shows jobs still open — the moment one is
     * awarded or lapses, a professional needs somewhere else to see what they bid
     * and what became of it.
     */
    public function index(ListQuotationsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewOwn', Quotation::class);

        $filters = $request->validated();

        $quotations = Quotation::query()
            // Scoped to the caller before any filter is applied, so a status or
            // search filter can only ever narrow this account's rows.
            ->byProfessional($request->user())
            ->forDisplay()
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['service_request_id']),
                fn (Builder $query) => $query->where('service_request_id', $filters['service_request_id']),
            )
            ->when(
                isset($filters['q']),
                fn (Builder $query) => $query->where(function (Builder $sub) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $sub->where('reference', 'like', $term)
                        ->orWhereHas(
                            'serviceRequest',
                            fn (Builder $job) => $job->where('title', 'like', $term)
                                ->orWhere('reference', 'like', $term),
                        );
                }),
            )
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? 'recent'))
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return QuotationResource::collection($quotations);
    }

    public function show(Request $request, Quotation $quotation): QuotationResource
    {
        $this->assertMine($request, $quotation);

        return QuotationResource::make($quotation->load(Quotation::DISPLAY_RELATIONS));
    }

    /**
     * Price a job.
     *
     * The items are written after the quotation, and each write makes the
     * quotation recalculate its four category totals — see QuotationItem. That
     * is why the response re-reads the row: the totals that matter are the ones
     * the lines produced, not the zeros the insert started from.
     */
    public function store(StoreQuotationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $serviceRequest = ServiceRequest::findOrFail($data['service_request_id']);

        Gate::authorize('create', [Quotation::class, $serviceRequest]);

        $quotation = $this->submit($request, $data, $serviceRequest);

        QuotationSubmitted::dispatch($quotation);

        return QuotationResource::make($quotation->refresh()->load(Quotation::DISPLAY_RELATIONS))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Revise a live offer.
     *
     * Without this, a professional who mistyped a figure would have to withdraw
     * and resubmit — which on a job they have already been shortlisted for reads
     * as walking away.
     */
    public function update(UpdateQuotationRequest $request, Quotation $quotation): QuotationResource
    {
        $this->assertMine($request, $quotation);

        Gate::authorize('update', $quotation);

        $data = $request->validated();

        DB::transaction(function () use ($quotation, $data): void {
            $quotation->update([
                'message' => $data['message'] ?? null,
                'estimated_days' => $data['estimated_days'] ?? null,
                'available_from' => $data['available_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
            ]);

            /*
             * The lines are replaced wholesale rather than reconciled.
             *
             * A breakdown has no identity worth preserving — nobody refers to
             * "line 3" — and matching incoming lines to stored ones would mean
             * guessing which of two identical descriptions was edited. The bulk
             * delete is deliberate: it does not fire the per-line events, which
             * would recompute the totals against a half-written breakdown. The
             * lines that replace these recompute them again, correctly.
             */
            $quotation->items()->delete();

            $this->writeItems($quotation, $data['items']);
        });

        return QuotationResource::make($quotation->refresh()->load(Quotation::DISPLAY_RELATIONS));
    }

    /**
     * Pull an offer before the customer has decided.
     *
     * The row stays. A professional who withdraws because the job turned out to
     * be somewhere else has not done anything wrong, but the customer's record of
     * who was asked, and who answered, should not quietly lose an entry.
     */
    public function withdraw(Request $request, Quotation $quotation): QuotationResource
    {
        $this->assertMine($request, $quotation);

        Gate::authorize('withdraw', $quotation);

        $quotation->withdraw();

        return QuotationResource::make($quotation->refresh()->load(Quotation::DISPLAY_RELATIONS));
    }

    /**
     * Creates the quotation and its lines as one write.
     *
     * @param  array<string, mixed>  $data
     */
    private function submit(
        StoreQuotationRequest $request,
        array $data,
        ServiceRequest $serviceRequest,
    ): Quotation {
        try {
            return DB::transaction(function () use ($request, $data, $serviceRequest): Quotation {
                $quotation = Quotation::createFor([
                    'service_request_id' => $serviceRequest->id,
                    'professional_id' => $request->user()->id,
                    'message' => $data['message'] ?? null,
                    'estimated_days' => $data['estimated_days'] ?? null,
                    'available_from' => $data['available_from'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                ]);

                $this->writeItems($quotation, $data['items']);

                /*
                 * The count the customer's list shows on every row. Denormalised
                 * rather than derived — see the service_requests migration — so it
                 * has to be maintained here.
                 */
                $serviceRequest->increment('quotes_count');

                /*
                 * The first offer moves the job from "open" to "quotes
                 * received", which is what tells the customer there is something
                 * to look at. A no-op on the second and later offers.
                 */
                $serviceRequest->markQuoted();

                return $quotation;
            });
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * One price per professional per job, enforced by a unique index
             * rather than only by a check the policy made a moment earlier. That
             * is the right place for it — a double submit cannot slip past an
             * index — but it arrives here as a database error, so it is
             * translated into the answer the professional can act on.
             */
            if (! str_contains($exception->getMessage(), 'professional_id')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'service_request_id' => 'You have already quoted on this job. Revise your existing quotation instead of sending another.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeItems(Quotation $quotation, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $quotation->items()->create([
                'category' => $item['category'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['unit_price_cents'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Somebody else's quotation answers 404, not 403.
     *
     * The route sits behind `role:professional`, so the only account that can
     * reach it is a professional — and a 403 would confirm which ids exist,
     * turning this into a way to watch a competitor's pipeline.
     */
    private function assertMine(Request $request, Quotation $quotation): void
    {
        abort_unless(
            $quotation->professional_id === $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * @param  Builder<Quotation>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest(),
            // Biggest first, so a professional can see where the money is.
            'value' => $query->orderByDesc('total_cents')->latest(),
            default => $query->latest(),
        };

        /*
         * A unique tiebreak. Without it, two quotations with the same sort key —
         * which `value` produces the moment two offers cost the same — can swap
         * places between requests, and a paging professional sees one twice while
         * missing another.
         */
        $query->orderBy('id');
    }
}
