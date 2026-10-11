<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Services\Quotations\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The quotations on a customer's own job posting (spec §9).
 *
 * Read and decided, never written: a customer does not submit a price, they
 * choose between the ones they were sent. The professional's side of this —
 * submitting, revising, withdrawing — lives in the professional namespace.
 *
 * Every route takes the request id as well as the quotation id, and the two are
 * checked against each other. Without that, a customer could accept a quotation
 * by pairing their own request's id with somebody else's quotation id and have
 * the policy look at the wrong row.
 */
class QuotationController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    /**
     * The comparison table: every offer on this job, strongest first.
     */
    public function index(Request $request, ServiceRequest $serviceRequest): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Quotation::class, $serviceRequest]);

        $quotations = Quotation::query()
            ->forRequest($serviceRequest)
            ->forDisplay()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return QuotationResource::collection($quotations);
    }

    /**
     * Award the job.
     *
     * The response carries the booking the award produced, because that is what
     * the customer's next screen is. Everything this touches — the siblings, the
     * request, the booking — happens inside one transaction in QuotationService.
     */
    public function accept(
        ServiceRequest $serviceRequest,
        Quotation $quotation,
        QuotationService $quotations,
    ): QuotationResource {
        $this->assertOnRequest($serviceRequest, $quotation);

        Gate::authorize('accept', $quotation);

        $result = $quotations->accept($quotation);

        return QuotationResource::make(
            $result['quotation']->refresh()->load(Quotation::DISPLAY_RELATIONS),
        );
    }

    /**
     * Turn one offer down and leave the job open to the others.
     *
     * Deliberately not a step towards awarding: a customer who rejects three of
     * four offers is still deciding, and the request stays on the board so a
     * second opinion can still arrive.
     */
    public function reject(ServiceRequest $serviceRequest, Quotation $quotation): QuotationResource
    {
        $this->assertOnRequest($serviceRequest, $quotation);

        Gate::authorize('reject', $quotation);

        $quotation->markRejected();

        return QuotationResource::make(
            $quotation->refresh()->load(Quotation::DISPLAY_RELATIONS),
        );
    }

    /**
     * The quotation must be one of the ones on the request in the path.
     */
    private function assertOnRequest(ServiceRequest $serviceRequest, Quotation $quotation): void
    {
        abort_unless(
            $quotation->service_request_id === $serviceRequest->id,
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * A page size the caller can choose within reason.
     *
     * Clamped rather than validated: this endpoint has no FormRequest of its own
     * because it takes no filters, and an unclamped `?per_page=100000` would be a
     * way to make the API assemble a customer's whole history in one request.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
    }
}
