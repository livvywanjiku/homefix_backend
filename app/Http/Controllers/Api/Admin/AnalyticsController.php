<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PlatformMetrics;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The drill-down analytics behind the dashboard cards (spec §20).
 *
 * Each section is its own endpoint because each is its own panel: a reader who
 * never scrolls to the location breakdown should not pay for its aggregation on
 * page load. All of them share the `range` parameter and all of them read from
 * the same service as the dashboard, so the headline figure and the chart
 * beneath it are the same number computed once.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly PlatformMetrics $metrics) {}

    /**
     * Revenue over time and against the previous period.
     *
     * Answers `available: false` until the payments slice ships, rather than a
     * line of zeroes that would read as "nobody has paid us".
     */
    public function revenue(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'revenue' => $this->metrics->revenue($range),
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'bookings' => $this->metrics->bookings($range),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'users' => $this->metrics->users($range),
        ]);
    }

    public function professionals(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'professionals' => $this->metrics->professionals($range),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'services' => $this->metrics->services($range),
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'locations' => $this->metrics->locations($range),
        ]);
    }
}
