<?php

namespace App\Http\Controllers\Api\Discovery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discovery\ListProfessionalSearchRequest;
use App\Http\Resources\ProfessionalCardResource;
use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

/**
 * Customer-facing professional search (spec §13).
 *
 * Open to guests on purpose: browsing who is available is exactly the part of
 * the product a visitor is allowed to see before signing up. Nothing here
 * requires or reveals an account.
 *
 * Every filter is applied to the query that hits the database. The client is
 * never sent a full list to sift through itself — the result set is paginated
 * here and nowhere else.
 */
class ProfessionalSearchController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    public function index(ListProfessionalSearchRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $origin = $this->origin($filters);

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        /*
         * Distance ordering is the one sort the database cannot do, because the
         * distance itself cannot be expressed in portable SQL — see
         * Location::idsWithin(). It takes a two-phase path; everything else
         * orders in the query.
         */
        $paginator = ($filters['sort'] ?? null) === 'distance' && $origin !== null
            ? $this->paginateByDistance($filters, $origin, $perPage, $page)
            : $this->applySort($this->filteredQuery($filters, $origin), $filters['sort'] ?? 'rating')
                ->paginate($perPage, ['*'], 'page', $page)
                ->withQueryString();

        /*
         * A distance is shown whenever the customer named a town, even if they
         * did not ask for a radius — "12 km away" is the single most useful
         * number on a local-services card, and the origin is already resolved.
         */
        if ($origin !== null) {
            $paginator->through(function (ProfessionalProfile $profile) use ($origin): ProfessionalProfile {
                $profile->distance_km = $this->distanceFrom($origin, $profile);

                return $profile;
            });
        }

        return ProfessionalCardResource::collection($paginator);
    }

    /**
     * The base query every search starts from: listed professionals only.
     *
     * `verified()` is not a filter the customer can switch off. The platform's
     * promise is verified trades, so an unvetted profile is never advertised in
     * search results — a pending professional is visible to themselves and to
     * the verifying administrator, and to nobody else.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ProfessionalProfile>
     */
    private function filteredQuery(array $filters, ?Location $origin): Builder
    {
        return $this->applyFilters(ProfessionalProfile::query()->verified(), $filters, $origin)
            ->forListing()
            ->withCount(['services' => fn ($services) => $services->active()]);
    }

    /**
     * Every filter except the eager loads, so the distance ranker can reuse them
     * without dragging a full profile graph along.
     *
     * @param  Builder<ProfessionalProfile>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ProfessionalProfile>
     */
    private function applyFilters(Builder $query, array $filters, ?Location $origin): Builder
    {
        return $query
            /*
             * A town was asked for that cannot anchor a search. The request
             * rules already restrict it to an active location, so this only
             * fires if the town is retired between validation and the query.
             * Failing closed keeps the promise the filter made — dropping it
             * silently would answer a different question than the one asked.
             */
            ->when(
                isset($filters['location_id']) && $origin === null,
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            )
            ->when(
                isset($filters['q']),
                fn (Builder $q) => $q->where(function (Builder $sub) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $sub->where('business_name', 'like', $term)
                        ->orWhere('about', 'like', $term)
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term))
                        /*
                         * The service names on offer, so "plumbing" finds
                         * plumbers — which is what a customer typing a trade
                         * into the search box is asking for. Without this the
                         * term would only match a business that happens to be
                         * called "… Plumbing".
                         */
                        ->orWhereHas(
                            'services',
                            fn ($services) => $services->active()->whereHas(
                                'service',
                                fn ($service) => $service->where('name', 'like', $term),
                            ),
                        );
                }),
            )
            ->when(
                isset($filters['service_id']),
                fn (Builder $q) => $q->whereHas(
                    'services',
                    fn ($services) => $services->active()->where('service_id', $filters['service_id']),
                ),
            )
            ->when(
                isset($filters['service_category_id']),
                fn (Builder $q) => $q->whereHas(
                    'services',
                    fn ($services) => $services
                        ->active()
                        ->whereHas(
                            'service',
                            fn ($service) => $service->where(
                                'service_category_id',
                                $filters['service_category_id'],
                            ),
                        ),
                ),
            )
            ->when(
                isset($filters['min_rating']),
                fn (Builder $q) => $q->where('rating_avg', '>=', $filters['min_rating']),
            )
            ->when($this->hasPriceBracket($filters), fn (Builder $q) => $this->applyPriceBracket($q, $filters))
            ->when(
                isset($filters['date']),
                fn (Builder $q) => $this->applyAvailability($q, $filters['date']),
            )
            ->when(
                isset($filters['location_id']) && ! isset($filters['max_distance_km']),
                fn (Builder $q) => $this->applyCoverage($q, [(int) $filters['location_id']]),
            )
            ->when(
                isset($filters['max_distance_km']) && $origin !== null,
                fn (Builder $q) => $this->applyRadius($q, $origin, (float) $filters['max_distance_km']),
            );
    }

    /**
     * The professional starts at a price inside the customer's bracket.
     *
     * Both bounds are applied inside a single `whereHas`, so one offering cannot
     * satisfy the floor while a different one satisfies the ceiling — which
     * would report a professional as affordable on the strength of two prices
     * they never quoted together.
     *
     * Quote-only offerings carry no starting price and are therefore excluded
     * from a bracketed search: there is no number to compare.
     *
     * @param  Builder<ProfessionalProfile>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ProfessionalProfile>
     */
    private function applyPriceBracket(Builder $query, array $filters): Builder
    {
        return $query->whereHas('services', function ($services) use ($filters): void {
            $services->active()->whereNotNull('price_min_cents');

            if (isset($filters['min_price_cents'])) {
                $services->where('price_min_cents', '>=', $filters['min_price_cents']);
            }

            if (isset($filters['max_price_cents'])) {
                $services->where('price_min_cents', '<=', $filters['max_price_cents']);
            }
        });
    }

    /**
     * Available on a given day: the weekday is in the weekly pattern and the
     * date is not blocked.
     *
     * Absence of an availability row is the "not working" signal — see the
     * availabilities migration — so a professional who never filled the grid in
     * is correctly reported as unavailable rather than assumed open.
     *
     * @param  Builder<ProfessionalProfile>  $query
     * @return Builder<ProfessionalProfile>
     */
    private function applyAvailability(Builder $query, string $date): Builder
    {
        $day = Carbon::parse($date);

        return $query
            ->whereHas('availabilities', fn ($availability) => $availability->onDay($day->dayOfWeek))
            ->whereDoesntHave(
                'availabilityExceptions',
                fn ($exception) => $exception->whereDate('blocked_on', $day->toDateString()),
            );
    }

    /**
     * Keeps professionals who work in any of the given towns.
     *
     * The base town counts as covered even when it was never added to the
     * service-area list — the same rule as
     * `ProfessionalProfile::coversLocation()`.
     *
     * @param  Builder<ProfessionalProfile>  $query
     * @param  array<int, int>  $locationIds
     * @return Builder<ProfessionalProfile>
     */
    private function applyCoverage(Builder $query, array $locationIds): Builder
    {
        return $query->where(function (Builder $sub) use ($locationIds): void {
            $sub->whereIn('base_location_id', $locationIds)
                ->orWhereHas('serviceAreas', fn ($areas) => $areas->whereIn('locations.id', $locationIds));
        });
    }

    /**
     * @param  Builder<ProfessionalProfile>  $query
     * @return Builder<ProfessionalProfile>
     */
    private function applyRadius(Builder $query, Location $origin, float $radiusKm): Builder
    {
        /*
         * A town with no coordinates cannot anchor a radius. Failing closed is
         * the honest answer: the alternative silently ignores the distance the
         * customer asked for and returns the whole country.
         */
        if ($origin->latitude === null || $origin->longitude === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyCoverage(
            $query,
            Location::idsWithin($origin->latitude, $origin->longitude, $radiusKm)->all(),
        );
    }

    /**
     * Ranks the whole candidate set by distance, then loads one page of it.
     *
     * Phase one reads only what the ordering needs — a profile id and its base
     * town's coordinates, joined rather than eager-loaded. Phase two fetches the
     * full profiles for the page that survived. Sorting every candidate in PHP
     * is affordable precisely because phase one reduced each one to a pair of
     * numbers; loading the full graph for all of them first would not be.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ProfessionalProfile>
     */
    private function paginateByDistance(
        array $filters,
        Location $origin,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $candidates = $this->applyFilters(ProfessionalProfile::query()->verified(), $filters, $origin)
            ->leftJoin('locations', 'locations.id', '=', 'professional_profiles.base_location_id')
            ->get([
                'professional_profiles.id as id',
                'locations.latitude as latitude',
                'locations.longitude as longitude',
            ]);

        $ranked = $candidates
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'distance_km' => Geo::distanceKm(
                    $origin->latitude,
                    $origin->longitude,
                    $row->latitude,
                    $row->longitude,
                ),
            ])
            /*
             * Unknown distance sorts last, not first. A professional whose base
             * town is unset has not been shown to be near, and must not outrank
             * one who demonstrably is.
             */
            ->sortBy(fn (array $row): float => $row['distance_km'] ?? PHP_FLOAT_MAX)
            ->values();

        $total = $ranked->count();
        $pageIds = $ranked->slice(($page - 1) * $perPage, $perPage)->pluck('id')->all();

        $order = array_flip($pageIds);

        $profiles = $pageIds === []
            ? collect()
            : ProfessionalProfile::query()
                ->whereIn('id', $pageIds)
                ->forListing()
                ->withCount(['services' => fn ($services) => $services->active()])
                ->get()
                // whereIn does not preserve the order of the ids, so the ranking
                // is reapplied here before the page is handed back.
                ->sortBy(fn (ProfessionalProfile $profile): int => $order[$profile->id] ?? PHP_INT_MAX)
                ->values();

        return new LengthAwarePaginator($profiles, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * @param  Builder<ProfessionalProfile>  $query
     * @return Builder<ProfessionalProfile>
     */
    private function applySort(Builder $query, string $sort): Builder
    {
        match ($sort) {
            'reviews' => $query->orderByDesc('rating_count')->orderByDesc('rating_avg'),

            'experience' => $query->orderByDesc('experience_years'),

            /*
             * Sorted on the cheapest active offering, computed as a correlated
             * aggregate. The `is null` term leads so that quote-only
             * professionals group at the end under both engines — PostgreSQL
             * and SQLite disagree about where NULLs belong in an ascending sort,
             * and this makes the order portable.
             */
            'price_low' => $query
                ->withMin(['services' => fn ($services) => $services->active()], 'price_min_cents')
                ->orderByRaw('services_min_price_min_cents is null')
                ->orderBy('services_min_price_min_cents'),

            'price_high' => $query
                ->withMin(['services' => fn ($services) => $services->active()], 'price_min_cents')
                ->orderByRaw('services_min_price_min_cents is null')
                ->orderByDesc('services_min_price_min_cents'),

            'newest' => $query->latest(),

            // The default: best-regarded first. Rating count breaks ties so a
            // single five-star review does not outrank two hundred at 4.8.
            default => $query->orderByDesc('rating_avg')
                ->orderByDesc('rating_count')
                ->orderByDesc('completed_jobs_count'),
        };

        /*
         * A unique tiebreak. Without it, two professionals with identical sort
         * keys can swap places between requests and a paginated customer sees
         * the same one twice while missing another.
         */
        return $query->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasPriceBracket(array $filters): bool
    {
        return isset($filters['min_price_cents']) || isset($filters['max_price_cents']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function origin(array $filters): ?Location
    {
        if (! isset($filters['location_id'])) {
            return null;
        }

        return Location::query()->active()->find($filters['location_id']);
    }

    private function distanceFrom(Location $origin, ProfessionalProfile $profile): ?float
    {
        if ($origin->latitude === null || $origin->longitude === null) {
            return null;
        }

        $base = $profile->baseLocation;

        return $base === null
            ? null
            : $base->distanceFrom($origin->latitude, $origin->longitude);
    }
}
