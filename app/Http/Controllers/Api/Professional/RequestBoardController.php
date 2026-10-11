<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\Professional\Concerns\InteractsWithProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\ListRequestBoardRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Models\ServiceRequest;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The board of open jobs a professional works from (spec §8).
 *
 * Reads only. Everything that acts on a request — quoting, booking — lives in
 * its own controller, so this class cannot accidentally become a way to modify
 * somebody else's posting.
 */
class RequestBoardController extends Controller
{
    use InteractsWithProfessionalProfile;

    public function index(ListRequestBoardRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewBoard', ServiceRequest::class);

        $profile = $this->profile($request->user());
        $filters = $request->validated();

        $query = ServiceRequest::query()
            ->listed()
            ->forDisplay()
            ->with('customer:id,name,avatar_path')
            ->withCount('images');

        /*
         * `matching` is the default because the alternative — every open job in
         * the country — buries the ones this professional can actually do. The
         * narrowing is an intersection, not a union: a job must be both a
         * service they offer *and* a town they cover.
         */
        if (($filters['scope'] ?? 'matching') === 'matching') {
            $this->applyMatchingScope($query, $profile);
        }

        $query
            ->when(isset($filters['service_id']), fn ($q) => $q->where('service_id', $filters['service_id']))
            ->when(
                isset($filters['service_category_id']),
                fn ($q) => $q->whereHas(
                    'service',
                    fn ($s) => $s->where('service_category_id', $filters['service_category_id']),
                ),
            )
            ->when(isset($filters['location_id']), fn ($q) => $q->where('location_id', $filters['location_id']))
            ->when(isset($filters['urgency']), fn ($q) => $q->where('urgency', $filters['urgency']))
            ->when(
                isset($filters['min_budget_cents']),
                // Matches when the customer's *ceiling* reaches the floor the
                // professional is asking for. A request with no stated budget is
                // excluded, because it cannot be shown to clear the bar.
                fn ($q) => $q->where('budget_max_cents', '>=', $filters['min_budget_cents']),
            )
            ->when(
                isset($filters['q']),
                fn ($q) => $q->where(function ($sub) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $sub->where('title', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('reference', 'like', $term);
                }),
            );

        if (isset($filters['max_distance_km'])) {
            $this->applyRadius($query, $profile, (float) $filters['max_distance_km']);
        }

        $this->applySort($query, $filters['sort'] ?? 'recent');

        $paginator = $query->paginate($filters['per_page'] ?? 15)->withQueryString();

        /*
         * Distance is attached to each row after pagination, because it can only
         * be computed in PHP. Doing it here rather than for the whole candidate
         * set keeps the cost proportional to the page, not the table.
         */
        $base = $profile->baseLocation;
        $paginator->through(function (ServiceRequest $serviceRequest) use ($base): ServiceRequest {
            $serviceRequest->distance_km = $this->distanceFrom($base, $serviceRequest);

            return $serviceRequest;
        });

        return ServiceRequestResource::collection($paginator);
    }

    public function show(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        // The policy decides: a listed job is readable by the trade, an awarded
        // or cancelled one only by its owner and administrators.
        Gate::authorize('view', $serviceRequest);

        return ServiceRequestResource::make(
            $serviceRequest->load([
                'service:id,name,slug,service_category_id',
                'service.category:id,name,slug',
                'location:id,name,region',
                'images',
                'customer:id,name,avatar_path',
            ]),
        );
    }

    /**
     * Narrows the board to what this professional sells, where they work.
     *
     * @param  Builder<ServiceRequest>  $query
     */
    private function applyMatchingScope(Builder $query, ProfessionalProfile $profile): void
    {
        $serviceIds = $profile->services()
            ->where('is_active', true)
            ->pluck('service_id');

        $locationIds = $profile->serviceAreas()->pluck('locations.id');

        // The base town counts as covered even if it was never added to the
        // service-area list — see ProfessionalProfile::coversLocation().
        if ($profile->base_location_id !== null) {
            $locationIds->push($profile->base_location_id);
        }

        $query->whereIn('service_id', $serviceIds)
            ->whereIn('location_id', $locationIds->unique()->values());
    }

    /**
     * Keeps only requests within a radius of the professional's base town.
     *
     * @param  Builder<ServiceRequest>  $query
     */
    private function applyRadius(Builder $query, ProfessionalProfile $profile, float $radiusKm): void
    {
        $base = $profile->baseLocation;

        /*
         * Without a base town there is no origin to measure from, so the filter
         * cannot be honoured. Failing closed (no rows) is the honest answer —
         * returning the whole board would silently ignore the radius the
         * professional asked for.
         */
        if ($base === null || $base->latitude === null || $base->longitude === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $baseLatitude = (float) $base->latitude;
        $baseLongitude = (float) $base->longitude;

        $inRange = Location::idsWithin($baseLatitude, $baseLongitude, $radiusKm);

        $box = Geo::boundingBox($baseLatitude, $baseLongitude, $radiusKm);

        /*
         * Two ways a request can qualify: it names a town inside the radius, or
         * the customer dropped a pin inside it. The second branch is a plain
         * range comparison, which is why it can run in SQL at all.
         */
        $query->where(function (Builder $sub) use ($inRange, $box): void {
            $sub->whereIn('location_id', $inRange)
                ->orWhere(function (Builder $pinned) use ($box): void {
                    $pinned->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereBetween('latitude', [$box['min_latitude'], $box['max_latitude']])
                        ->whereBetween('longitude', [$box['min_longitude'], $box['max_longitude']]);
                });
        });
    }

    /**
     * The distance to show on a board row, or null when it cannot be known.
     *
     * Prefers the customer's own pin over the town's centre — a job pinned to a
     * specific house is more useful to a travelling tradesperson than the
     * centroid of its town.
     */
    private function distanceFrom(?Location $base, ServiceRequest $serviceRequest): ?float
    {
        if ($base === null) {
            return null;
        }

        if ($serviceRequest->latitude !== null && $serviceRequest->longitude !== null) {
            return Geo::distanceKm(
                $base->latitude,
                $base->longitude,
                $serviceRequest->latitude,
                $serviceRequest->longitude,
            );
        }

        $town = $serviceRequest->location;

        return $town === null
            ? null
            : Geo::distanceKm($base->latitude, $base->longitude, $town->latitude, $town->longitude);
    }

    /**
     * @param  Builder<ServiceRequest>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            // Urgent first within the freshest listings, so a same-day job is not
            // pushed off the first page by a week of flexible ones.
            'urgent' => $query->orderByRaw(
                "case urgency when 'urgent' then 0 when 'soon' then 1 else 2 end",
            )->latest(),
            'budget' => $query->orderByDesc('budget_max_cents')->latest(),
            'expiring' => $query->orderByRaw('expires_at is null')->orderBy('expires_at'),
            default => $query->latest(),
        };
    }
}
