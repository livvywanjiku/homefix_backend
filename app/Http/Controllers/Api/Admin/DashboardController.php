<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PlatformMetrics;
use App\Services\Admin\SystemHealth;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administration dashboard's own endpoints (spec §20).
 *
 * The summary is deliberately small: the twelve statistic cards, the activity
 * timeline, the pending-work list and the health panel. Everything heavier —
 * trend series, breakdowns, leaderboards — lives on the analytics endpoints and
 * is fetched by the panel that draws it, so opening the dashboard does not run
 * every aggregation the platform knows how to run.
 *
 * Every method answers the same `range` query parameter, resolved once by
 * {@see DateRange}, so a figure on the dashboard and the same figure on the
 * analytics page cannot disagree about what period they describe.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly PlatformMetrics $metrics,
        private readonly SystemHealth $health,
    ) {}

    /**
     * The dashboard summary.
     */
    public function index(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'presets' => DateRange::PRESETS,
            'stats' => $this->metrics->stats($range),
            'activity' => $this->metrics->activity(12),
            'pending_actions' => $this->metrics->pendingActions(),
            'system_health' => $this->health->report(),
            'system_health_overall' => $this->health->overall(),
            'availability' => $this->metrics->availability(),
        ]);
    }

    /**
     * The statistic cards on their own, for a date-filter change that should not
     * re-fetch the whole page.
     */
    public function statistics(Request $request): JsonResponse
    {
        $range = DateRange::fromRequest($request);

        return response()->json([
            'range' => $range->toArray(),
            'stats' => $this->metrics->stats($range),
        ]);
    }

    /**
     * The recent-activity timeline.
     */
    public function activity(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 12);
        $limit = max(1, min($limit, 50));

        return response()->json(['activity' => $this->metrics->activity($limit)]);
    }

    /**
     * What is waiting on an administrator.
     */
    public function pendingActions(): JsonResponse
    {
        return response()->json(['pending_actions' => $this->metrics->pendingActions()]);
    }

    /**
     * The integrations panel, for polling without reloading the dashboard.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'system_health' => $this->health->report(),
            'overall' => $this->health->overall(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
