<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminServiceRequestsRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every job posted on the platform (spec §20).
 *
 * Read-only, and deliberately so. A service request is a customer's own
 * advertisement of work; an administrator overriding its terms would be
 * rewriting what a professional is being asked to price. What the console does
 * with this screen is look: at volume, at where jobs are, at what is not
 * attracting quotations.
 */
class ServiceRequestAdminController extends Controller
{
    /**
     * The request list, filtered and paginated.
     */
    public function index(ListAdminServiceRequestsRequest $request): AnonymousResourceCollection
    {
        $requests = ServiceRequest::query()
            ->with([
                'customer:id,name,email,phone,avatar_path',
                'service:id,name,slug',
                'location:id,name,region',
            ])
            ->when(
                $request->validated('status'),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $request->validated('service_id'),
                fn ($query, int $service) => $query->where('service_id', $service),
            )
            ->when(
                $request->validated('location_id'),
                fn ($query, int $location) => $query->where('location_id', $location),
            )
            ->when($request->validated('q'), function ($query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('title', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when(
                $request->validated('sort'),
                fn ($query, string $sort) => match ($sort) {
                    'oldest' => $query->orderBy('created_at'),
                    'quotes' => $query->orderByDesc('quotes_count'),
                    // Highest budget first, with the ones that named no budget
                    // last rather than at zero — an unstated budget is not a
                    // budget of nothing.
                    'budget' => $query->orderByRaw('budget_max_cents IS NULL')->orderByDesc('budget_max_cents'),
                    default => $query->orderByDesc('created_at'),
                },
                fn ($query) => $query->orderByDesc('created_at'),
            )
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ServiceRequestResource::collection($requests);
    }

    /**
     * One request, with the quotations it attracted.
     */
    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return ServiceRequestResource::make($serviceRequest->load([
            'customer:id,name,email,phone,avatar_path',
            'service:id,name,slug',
            'location:id,name,region',
            'images',
            /*
             * The offers, with everything the comparison needs. The trade's
             * business name and record come from the profile hanging off the
             * account, and the itemised breakdown from `items` — loaded here
             * rather than left to lazy-loading, which would put a query behind
             * every row of the table.
             */
            'quotations.items',
            'quotations.professional:id,name,avatar_path',
            'quotations.professional.professionalProfile:id,user_id,business_name,rating_avg,rating_count,completed_jobs_count',
        ]));
    }
}
