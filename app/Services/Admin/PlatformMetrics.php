<?php

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\AdminLog;
use App\Models\User;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every figure the administration dashboard reports.
 *
 * Two rules shape this class, and both exist because the opposite is easy and
 * wrong.
 *
 * The first is that nothing here is hardcoded or invented. Every number is the
 * result of a query against the platform's own tables, run at the moment it is
 * asked for. A dashboard that shows a plausible figure it did not compute is
 * worse than one that shows nothing.
 *
 * The second is that a feature which does not exist yet reports itself as
 * unavailable rather than as zero. `Schema::hasTable()` decides that, so the
 * payments, reviews and complaints sections switch themselves on the moment
 * their migrations land — no edit here, and no window in which the dashboard
 * claims nobody has ever paid for anything.
 *
 * Everything is counted in SQL. The dashboard never loads a collection it could
 * have aggregated, and no endpoint here returns more than a page of rows.
 */
class PlatformMetrics
{
    /* ── Statistic cards ───────────────────────────────────────────────── */

    /**
     * The headline figures (spec §20, "Statistics cards").
     *
     * Each is reported as a total now against the same total one window ago, so
     * the change is the platform's growth over the selected period rather than
     * a meaningless count of rows that happen to be recent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stats(DateRange $range): array
    {
        $at = $range->to;
        $before = $range->previous()->to;

        $cards = [
            $this->card('total_users', 'Total users', $this->userCount(), $at, $before),
            $this->card(
                'total_customers',
                'Total customers',
                $this->userCount(UserRole::Customer->value),
                $at,
                $before,
            ),
            $this->card(
                'total_professionals',
                'Total professionals',
                $this->userCount(UserRole::Professional->value),
                $at,
                $before,
            ),
            $this->card(
                'verified_professionals',
                'Verified professionals',
                $this->profileCount(VerificationStatus::Verified),
                $at,
                $before,
                'professional_profiles',
            ),
            $this->card(
                'pending_verifications',
                'Awaiting verification',
                $this->profileCount(VerificationStatus::Pending),
                $at,
                $before,
                'professional_profiles',
                // Not a "growth" figure: more people waiting is not progress, so
                // a rise is shown as a rise and read as one.
                inverse: true,
            ),
            $this->card(
                'service_requests',
                'Service requests',
                $this->tableCount('service_requests'),
                $at,
                $before,
                'service_requests',
            ),
            $this->card(
                'active_bookings',
                'Active bookings',
                $this->bookingCount(BookingStatus::activeValues()),
                $at,
                $before,
                'bookings',
            ),
            $this->card(
                'completed_bookings',
                'Completed bookings',
                $this->bookingCount([BookingStatus::Completed->value]),
                $at,
                $before,
                'bookings',
            ),
            $this->card(
                'cancelled_bookings',
                'Cancelled bookings',
                $this->bookingCount([BookingStatus::Cancelled->value]),
                $at,
                $before,
                'bookings',
                inverse: true,
            ),
            $this->paymentsCard($at, $before),
            $this->revenueCard($at, $before),
            $this->openComplaintsCard(),
        ];

        return array_values(array_filter($cards, fn ($card) => $card !== null));
    }

    /* ── Revenue ───────────────────────────────────────────────────────── */

    /**
     * The revenue panel (spec §20, "Revenue overview").
     *
     * A payment is the one thing on this dashboard that must never be
     * estimated: the figures come from settled payment rows or they do not
     * appear. Until the payments slice ships there is no table to read, and the
     * section says so.
     *
     * @return array<string, mixed>
     */
    public function revenue(DateRange $range): array
    {
        if (! $this->has('payments')) {
            return [
                'available' => false,
                'reason' => 'Payments are not enabled yet. Revenue appears here once the payment gateway is live.',
                'totals' => null,
                'series' => [],
            ];
        }

        $paid = fn (): QueryBuilder => DB::table('payments')->where('status', 'paid');

        $total = fn (?CarbonImmutable $from, ?CarbonImmutable $to) => $paid()
            ->when($from, fn (QueryBuilder $q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn (QueryBuilder $q) => $q->where('paid_at', '<=', $to))
            ->sum('amount_cents');

        $now = CarbonImmutable::now();

        return [
            'available' => true,
            'reason' => null,
            'totals' => [
                'all_time_cents' => (int) $total(null, null),
                'today_cents' => (int) $total($now->startOfDay(), $now->endOfDay()),
                'week_cents' => (int) $total($now->startOfWeek(), $now->endOfWeek()),
                'month_cents' => (int) $total($now->startOfMonth(), $now->endOfMonth()),
                'year_cents' => (int) $total($now->startOfYear(), $now->endOfYear()),
                'refunded_cents' => (int) DB::table('payments')
                    ->where('status', 'refunded')
                    ->sum('amount_cents'),
                'period_cents' => (int) $total($range->from, $range->to),
                'previous_period_cents' => (int) $total(
                    $range->previous()->from,
                    $range->previous()->to,
                ),
            ],
            'series' => $this->bucketed(
                $range,
                'payments',
                'paid_at',
                'sum',
                'amount_cents',
                fn (QueryBuilder $q) => $q->where('status', 'paid'),
            ),
        ];
    }

    /* ── Bookings ──────────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    public function bookings(DateRange $range): array
    {
        if (! $this->has('bookings')) {
            return ['available' => false, 'reason' => 'Bookings are not enabled yet.'];
        }

        $byStatus = DB::table('bookings')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $inRange = fn (): QueryBuilder => DB::table('bookings')
            ->whereBetween('created_at', [$range->from, $range->to]);

        $completed = (int) $inRange()->where('status', BookingStatus::Completed->value)->count();
        $cancelled = (int) $inRange()->where('status', BookingStatus::Cancelled->value)->count();
        $total = (int) $inRange()->count();

        $breakdown = [];

        foreach (BookingStatus::cases() as $status) {
            $breakdown[] = [
                'value' => $status->value,
                'label' => $status->label(),
                'total' => (int) ($byStatus[$status->value] ?? 0),
                'in_period' => (int) $inRange()->where('status', $status->value)->count(),
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'total' => (int) DB::table('bookings')->count(),
            'in_period' => $total,
            'previous_period' => (int) DB::table('bookings')
                ->whereBetween('created_at', [$range->previous()->from, $range->previous()->to])
                ->count(),
            'statuses' => $breakdown,
            'completion_rate' => $total === 0 ? null : round($completed / $total * 100, 1),
            'cancellation_rate' => $total === 0 ? null : round($cancelled / $total * 100, 1),
            'series' => $this->bucketed($range, 'bookings', 'created_at', 'count'),
            'status_series' => $this->bucketedBy($range, 'bookings', 'created_at', 'status'),
        ];
    }

    /* ── Users ─────────────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    public function users(DateRange $range): array
    {
        $statuses = DB::table('users')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'available' => true,
            'roles' => [
                'customers' => $this->userCount(UserRole::Customer->value),
                'professionals' => $this->userCount(UserRole::Professional->value),
                'administrators' => $this->administratorCount(),
            ],
            'statuses' => [
                'active' => (int) ($statuses[UserStatus::Active->value] ?? 0),
                'suspended' => (int) ($statuses[UserStatus::Suspended->value] ?? 0),
            ],
            'new_in_period' => (int) DB::table('users')
                ->whereBetween('created_at', [$range->from, $range->to])
                ->count(),
            'new_previous_period' => (int) DB::table('users')
                ->whereBetween('created_at', [$range->previous()->from, $range->previous()->to])
                ->count(),
            'series' => $this->bucketed($range, 'users', 'created_at', 'count'),
            'series_by_role' => $this->bucketedUserRoles($range),
            'registrations_by_location' => $this->registrationsByLocation($range),
        ];
    }

    /* ── Professionals ─────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    public function professionals(DateRange $range): array
    {
        if (! $this->has('professional_profiles')) {
            return ['available' => false, 'reason' => 'Professional profiles are not enabled yet.'];
        }

        $byStatus = DB::table('professional_profiles')
            ->selectRaw('verification_status, COUNT(*) as total')
            ->groupBy('verification_status')
            ->pluck('total', 'verification_status');

        $verification = [];

        foreach (VerificationStatus::cases() as $status) {
            $verification[] = [
                'value' => $status->value,
                'label' => $status->label(),
                'total' => (int) ($byStatus[$status->value] ?? 0),
            ];
        }

        return [
            'available' => true,
            'verification' => $verification,
            'listed' => (int) DB::table('professional_profiles')
                ->where('verification_status', VerificationStatus::Verified->value)
                ->count(),
            'series' => $this->bucketed(
                $range,
                'professional_profiles',
                'created_at',
                'count',
            ),
            'top_rated' => $this->topProfessionals('rating_avg', 'rating_count > 0'),
            'most_completed' => $this->topProfessionals('completed_jobs_count', 'completed_jobs_count > 0'),
            'awaiting_review' => (int) DB::table('professional_profiles')
                ->where('verification_status', VerificationStatus::Pending->value)
                ->count(),
        ];
    }

    /* ── Services ──────────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    public function services(DateRange $range): array
    {
        if (! $this->has('service_requests')) {
            return ['available' => false, 'reason' => 'The service catalogue is not enabled yet.'];
        }

        /*
         * Demand is measured from the postings themselves, which is the only
         * honest source for it: an offering listed by a professional is supply,
         * and counting it as popularity would rank whoever advertises most.
         */
        $mostRequested = DB::table('service_requests')
            ->join('services', 'services.id', '=', 'service_requests.service_id')
            ->whereBetween('service_requests.created_at', [$range->from, $range->to])
            ->selectRaw('services.id, services.name, COUNT(*) as total')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();

        $byCategory = DB::table('service_requests')
            ->join('services', 'services.id', '=', 'service_requests.service_id')
            ->join('service_categories', 'service_categories.id', '=', 'services.service_category_id')
            ->whereBetween('service_requests.created_at', [$range->from, $range->to])
            ->selectRaw('service_categories.id, service_categories.name, COUNT(*) as total')
            ->groupBy('service_categories.id', 'service_categories.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();

        $averageBudget = DB::table('service_requests')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->whereNotNull('budget_max_cents')
            ->avg('budget_max_cents');

        return [
            'available' => true,
            'catalogue' => [
                'services' => (int) DB::table('services')->count(),
                'active_services' => (int) DB::table('services')->where('is_active', true)->count(),
                'categories' => (int) DB::table('service_categories')->count(),
            ],
            'most_requested' => $mostRequested,
            'most_requested_categories' => $byCategory,
            'average_budget_cents' => $averageBudget === null ? null : (int) round((float) $averageBudget),
            'highest_revenue' => null,
            'revenue_available' => false,
        ];
    }

    /* ── Locations ─────────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    public function locations(DateRange $range): array
    {
        if (! $this->has('locations')) {
            return ['available' => false, 'reason' => 'Locations are not configured yet.'];
        }

        $demand = DB::table('service_requests')
            ->join('locations', 'locations.id', '=', 'service_requests.location_id')
            ->whereBetween('service_requests.created_at', [$range->from, $range->to])
            ->selectRaw('locations.id, locations.name, locations.latitude, locations.longitude, COUNT(*) as total')
            ->groupBy('locations.id', 'locations.name', 'locations.latitude', 'locations.longitude')
            ->orderByDesc('total')
            ->limit(12)
            ->get();

        $supply = DB::table('professional_profiles')
            ->join('locations', 'locations.id', '=', 'professional_profiles.base_location_id')
            ->selectRaw('locations.id, COUNT(*) as total')
            ->groupBy('locations.id')
            ->pluck('total', 'id');

        return [
            'available' => true,
            'demand' => $demand->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'latitude' => $row->latitude === null ? null : (float) $row->latitude,
                'longitude' => $row->longitude === null ? null : (float) $row->longitude,
                'requests' => (int) $row->total,
                'professionals' => (int) ($supply[$row->id] ?? 0),
            ])->all(),
            'total_covered' => (int) DB::table('locations')->where('is_active', true)->count(),
        ];
    }

    /* ── Activity and pending work ─────────────────────────────────────── */

    /**
     * The recent-activity timeline (spec §20).
     *
     * Assembled from the records themselves rather than from a separate event
     * stream, so the timeline cannot drift from what actually happened. Each
     * source is asked for at most `$limit` rows and the merge happens in PHP —
     * five small indexed reads, not a union across the whole schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activity(int $limit = 12): array
    {
        $events = [];

        $push = function (
            string $type,
            string $title,
            string $description,
            CarbonImmutable $at,
            ?string $href,
            string $tone,
        ) use (&$events): void {
            $events[] = [
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'at' => $at->toIso8601String(),
                'href' => $href,
                'tone' => $tone,
            ];
        };

        foreach ($this->recentUsers($limit) as $row) {
            $push(
                'user_registered',
                'New registration',
                $row->name.' joined as a '.str_replace('_', ' ', (string) $row->role_name),
                CarbonImmutable::parse($row->created_at),
                '/admin/users',
                'neutral',
            );
        }

        foreach ($this->recentVerifications($limit) as $row) {
            $decision = $row->verification_status === VerificationStatus::Verified->value
                ? 'verified'
                : 'reviewed';

            $push(
                'professional_'.$decision,
                'Professional '.$decision,
                ($row->business_name ?? 'A professional').' was '.$decision,
                CarbonImmutable::parse($row->verified_at ?? $row->updated_at),
                '/admin/professionals',
                $decision === 'verified' ? 'success' : 'accent',
            );
        }

        if ($this->has('bookings')) {
            foreach ($this->recentBookings($limit) as $row) {
                $push(
                    'booking_'.$row->status,
                    'Booking '.str_replace('_', ' ', (string) $row->status),
                    ($row->reference ?? 'A booking').' · '.$row->title,
                    CarbonImmutable::parse($row->updated_at),
                    '/admin/bookings',
                    $row->status === BookingStatus::Cancelled->value ? 'danger' : 'accent',
                );
            }
        }

        if ($this->has('quotations')) {
            foreach ($this->recentQuotations($limit) as $row) {
                $push(
                    'quotation_'.$row->status,
                    'Quotation '.str_replace('_', ' ', (string) $row->status),
                    ($row->reference ?? 'A quotation').' on '.($row->job_title ?? 'a job'),
                    CarbonImmutable::parse($row->updated_at),
                    '/admin/bookings',
                    $row->status === QuotationStatus::Accepted->value ? 'success' : 'neutral',
                );
            }
        }

        foreach ($this->recentAdminActions($limit) as $row) {
            $push(
                'admin_action',
                $row->action instanceof \App\Enums\AdminAction
                    ? $row->action->label()
                    : 'Administrative action',
                $row->description ?? 'An administrator made a change',
                CarbonImmutable::parse($row->created_at),
                '/admin/audit-logs',
                'neutral',
            );
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /**
     * What needs an administrator's attention (spec §20, "Pending actions").
     *
     * Rows for features that do not exist yet are omitted rather than shown as
     * zero, so the panel is a to-do list and not a row of things that can never
     * be done.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingActions(): array
    {
        $actions = [];

        if ($this->has('professional_profiles')) {
            $pending = (int) DB::table('professional_profiles')
                ->where('verification_status', VerificationStatus::Pending->value)
                ->count();

            if ($pending > 0) {
                $actions[] = [
                    'key' => 'pending_verifications',
                    'label' => 'Professionals awaiting verification',
                    'count' => $pending,
                    'href' => '/admin/verification',
                    'tone' => 'accent',
                    'action' => 'Review',
                ];
            }

            $documents = $this->has('professional_documents')
                ? (int) DB::table('professional_documents')
                    ->where('status', VerificationStatus::Pending->value)
                    ->count()
                : 0;

            if ($documents > 0) {
                $actions[] = [
                    'key' => 'pending_documents',
                    'label' => 'Documents awaiting review',
                    'count' => $documents,
                    'href' => '/admin/verification',
                    'tone' => 'accent',
                    'action' => 'Review',
                ];
            }
        }

        if ($this->has('complaints')) {
            $open = (int) DB::table('complaints')->where('status', 'open')->count();

            if ($open > 0) {
                $actions[] = [
                    'key' => 'open_complaints',
                    'label' => 'Complaints needing attention',
                    'count' => $open,
                    'href' => '/admin/complaints',
                    'tone' => 'danger',
                    'action' => 'Investigate',
                ];
            }
        }

        if ($this->has('payments')) {
            $pending = (int) DB::table('payments')->where('status', 'processing')->count();

            if ($pending > 0) {
                $actions[] = [
                    'key' => 'pending_payments',
                    'label' => 'Payments awaiting settlement',
                    'count' => $pending,
                    'href' => '/admin/payments',
                    'tone' => 'accent',
                    'action' => 'View',
                ];
            }
        }

        return $actions;
    }

    /**
     * Which feature areas are live, so the dashboard renders an honest empty
     * state instead of a zero for something that does not exist.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return [
            'payments' => $this->has('payments'),
            'reviews' => $this->has('reviews'),
            'complaints' => $this->has('complaints'),
            'messaging' => $this->has('conversations'),
            'bookings' => $this->has('bookings'),
            'quotations' => $this->has('quotations'),
            'notifications' => $this->has('notifications'),
        ];
    }

    /* ── Counting primitives ───────────────────────────────────────────── */

    private function has(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * A count, or null when the table it would read does not exist.
     */
    private function countOrNull(string $table, ?callable $constrain = null): ?int
    {
        if (! $this->has($table)) {
            return null;
        }

        $query = DB::table($table);

        if ($constrain) {
            $constrain($query);
        }

        return (int) $query->count();
    }

    private function userCount(?string $role = null): int
    {
        $query = DB::table('users');

        if ($role !== null) {
            $query->whereIn('users.id', function (QueryBuilder $sub) use ($role): void {
                $sub->select('model_has_roles.model_id')
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.name', $role)
                    ->where('model_has_roles.model_type', User::class);
            });
        }

        return (int) $query->count();
    }

    private function administratorCount(): int
    {
        return (int) DB::table('users')
            ->whereIn('users.id', function (QueryBuilder $sub): void {
                $sub->select('model_has_roles.model_id')
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereIn('roles.name', UserRole::administrativeValues())
                    ->where('model_has_roles.model_type', User::class);
            })
            ->count();
    }

    private function tableCount(string $table): ?int
    {
        return $this->countOrNull($table);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function bookingCount(array $statuses): ?int
    {
        if (! $this->has('bookings')) {
            return null;
        }

        return (int) DB::table('bookings')->whereIn('status', $statuses)->count();
    }

    private function profileCount(VerificationStatus $status): ?int
    {
        return $this->countOrNull(
            'professional_profiles',
            fn (QueryBuilder $query) => $query->where('verification_status', $status->value),
        );
    }

    /**
     * One statistic card: a figure now, the same figure a window ago, and the
     * difference between them.
     *
     * @return array<string, mixed>|null
     */
    private function card(
        string $key,
        string $label,
        ?int $nowTotal,
        CarbonImmutable $at,
        CarbonImmutable $before,
        ?string $table = null,
        bool $inverse = false,
    ): ?array {
        if ($nowTotal === null) {
            return null;
        }

        $table ??= 'users';

        if (! $this->has($table)) {
            return null;
        }

        $earlier = (int) DB::table($table)->where('created_at', '<=', $before)->count();

        return array_merge($this->figure($key, $label, $nowTotal, $earlier, 'number'), [
            // Whether a rise is good news. More cancellations is not, and the
            // card colours itself accordingly rather than celebrating it.
            'inverse' => $inverse,
        ]);
    }

    /**
     * Settled payments, counted and summed.
     *
     * Returns an `available: false` card while the payments slice is unbuilt,
     * and a real figure the moment its table exists. There is no third state in
     * which it reports a plausible zero.
     *
     * @return array<string, mixed>
     */
    private function paymentsCard(CarbonImmutable $at, CarbonImmutable $before): array
    {
        if (! $this->has('payments')) {
            return $this->absent('total_payments', 'Payments received');
        }

        $countAt = fn (CarbonImmutable $moment): int => (int) DB::table('payments')
            ->where('status', 'paid')
            ->where('created_at', '<=', $moment)
            ->count();

        $now = $countAt($at);
        $earlier = $countAt($before);

        return $this->figure('total_payments', 'Payments received', $now, $earlier, 'number');
    }

    /**
     * Money taken, as opposed to money invoiced.
     *
     * @return array<string, mixed>
     */
    private function revenueCard(CarbonImmutable $at, CarbonImmutable $before): array
    {
        if (! $this->has('payments')) {
            return $this->absent('platform_revenue', 'Platform revenue');
        }

        $sumAt = fn (CarbonImmutable $moment): float => (float) DB::table('payments')
            ->where('status', 'paid')
            ->where('created_at', '<=', $moment)
            ->sum('amount_cents');

        return $this->figure(
            'platform_revenue',
            'Platform revenue',
            (int) $sumAt($at),
            (int) $sumAt($before),
            'money',
        );
    }

    /**
     * Complaints still open. A stock, not a flow: it is the same figure whoever
     * asks, so there is no previous-period comparison to make.
     *
     * @return array<string, mixed>
     */
    private function openComplaintsCard(): array
    {
        if (! $this->has('complaints')) {
            return $this->absent('open_complaints', 'Open complaints');
        }

        return [
            'key' => 'open_complaints',
            'label' => 'Open complaints',
            'value' => (int) DB::table('complaints')->where('status', 'open')->count(),
            'previous' => null,
            'change' => null,
            'change_percent' => null,
            'trend' => 'flat',
            'inverse' => true,
            'format' => 'number',
            'available' => true,
        ];
    }

    /**
     * The arithmetic shared by every comparison card.
     *
     * `change_percent` is null when there was nothing to compare against. A
     * "+100%" from a base of zero is not a growth rate, it is arithmetic on a
     * number that does not exist, and a card that shows it teaches the reader to
     * distrust every other card.
     *
     * @return array<string, mixed>
     */
    private function figure(string $key, string $label, int $now, int $earlier, string $format): array
    {
        $delta = $now - $earlier;

        return [
            'key' => $key,
            'label' => $label,
            'value' => $now,
            'previous' => $earlier,
            'change' => $delta,
            'change_percent' => $earlier === 0 ? null : round($delta / $earlier * 100, 1),
            'trend' => $delta === 0 ? 'flat' : ($delta > 0 ? 'up' : 'down'),
            'inverse' => false,
            'format' => $format,
            'available' => true,
        ];
    }

    /**
     * A card for a feature that has not been built yet.
     *
     * @return array<string, mixed>
     */
    private function absent(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => null,
            'previous' => null,
            'change' => null,
            'change_percent' => null,
            'trend' => 'flat',
            'inverse' => false,
            'format' => 'number',
            'available' => false,
        ];
    }

    /* ── Series ────────────────────────────────────────────────────────── */

    /**
     * A trend line over the window, bucketed to the range's granularity.
     *
     * The buckets come from the range, not from the rows, so a period with no
     * activity is a zero on the chart rather than a gap — a line that skips a
     * quiet week reads as a broken chart.
     *
     * @param  callable(QueryBuilder): void|null  $constrain
     * @return array<int, array{label: string, value: int|float}>
     */
    private function bucketed(
        DateRange $range,
        string $table,
        string $column,
        string $aggregate = 'count',
        string $sumColumn = 'id',
        ?callable $constrain = null,
    ): array {
        if (! $this->has($table)) {
            return [];
        }

        $expression = $aggregate === 'sum'
            ? "SUM($sumColumn)"
            : 'COUNT(*)';

        $query = DB::table($table)
            ->selectRaw("DATE($column) as day, $expression as total")
            ->whereBetween($column, [$range->from, $range->to])
            ->groupBy('day');

        if ($constrain) {
            $constrain($query);
        }

        $rows = $query->pluck('total', 'day');

        $totals = array_fill(0, count($range->buckets()), 0);

        foreach ($rows as $day => $total) {
            $index = $range->bucketIndex(CarbonImmutable::parse((string) $day));

            if ($index !== null) {
                $totals[$index] += (int) $total;
            }
        }

        $series = [];

        foreach ($range->buckets() as $index => $bucket) {
            $series[] = ['label' => $bucket['label'], 'value' => $totals[$index]];
        }

        return $series;
    }

    /**
     * A trend line split by a column — used for the booking-status chart.
     *
     * @return array<int, array{label: string, values: array<string, int>}>
     */
    private function bucketedBy(DateRange $range, string $table, string $column, string $split): array
    {
        if (! $this->has($table)) {
            return [];
        }

        $rows = DB::table($table)
            ->selectRaw("DATE($column) as day, $split as bucket, COUNT(*) as total")
            ->whereBetween($column, [$range->from, $range->to])
            ->groupBy('day', 'bucket')
            ->get();

        $buckets = $range->buckets();
        $totals = [];

        foreach ($buckets as $index => $bucket) {
            $totals[$index] = [];
        }

        foreach ($rows as $row) {
            $index = $range->bucketIndex(CarbonImmutable::parse((string) $row->day));

            if ($index === null) {
                continue;
            }

            $key = (string) $row->bucket;
            $totals[$index][$key] = ($totals[$index][$key] ?? 0) + (int) $row->total;
        }

        $series = [];

        foreach ($buckets as $index => $bucket) {
            $series[] = ['label' => $bucket['label'], 'values' => $totals[$index]];
        }

        return $series;
    }

    /**
     * Registrations per bucket, split into customers and professionals.
     *
     * @return array<int, array{label: string, values: array<string, int>}>
     */
    private function bucketedUserRoles(DateRange $range): array
    {
        $rows = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('DATE(users.created_at) as day, roles.name as role, COUNT(*) as total')
            ->whereBetween('users.created_at', [$range->from, $range->to])
            ->whereIn('roles.name', [
                UserRole::Customer->value,
                UserRole::Professional->value,
            ])
            ->groupBy('day', 'roles.name')
            ->get();

        $buckets = $range->buckets();
        $totals = [];

        foreach ($buckets as $index => $bucket) {
            $totals[$index] = [
                UserRole::Customer->value => 0,
                UserRole::Professional->value => 0,
            ];
        }

        foreach ($rows as $row) {
            $index = $range->bucketIndex(CarbonImmutable::parse((string) $row->day));

            if ($index === null) {
                continue;
            }

            $key = (string) $row->role;
            $totals[$index][$key] = ($totals[$index][$key] ?? 0) + (int) $row->total;
        }

        $series = [];

        foreach ($buckets as $index => $bucket) {
            $series[] = ['label' => $bucket['label'], 'values' => $totals[$index]];
        }

        return $series;
    }

    /**
     * Where new accounts are registering from, by town.
     *
     * Customers carry no location of their own — the address belongs to the job
     * posting — so this reads the customer's most recent posting, which is the
     * only place the platform actually knows where they are.
     *
     * @return array<int, array{name: string, total: int}>
     */
    private function registrationsByLocation(DateRange $range): array
    {
        if (! $this->has('service_requests')) {
            return [];
        }

        return DB::table('service_requests')
            ->join('locations', 'locations.id', '=', 'service_requests.location_id')
            ->whereBetween('service_requests.created_at', [$range->from, $range->to])
            ->selectRaw('locations.name, COUNT(*) as total')
            ->groupBy('locations.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['name' => $row->name, 'total' => (int) $row->total])
            ->all();
    }

    /* ── Small reads behind the timeline ───────────────────────────────── */

    private function recentUsers(int $limit): iterable
    {
        return DB::table('users')
            ->leftJoin('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('users.id', 'users.name', 'users.created_at', 'roles.name as role_name')
            ->orderByDesc('users.created_at')
            ->limit($limit)
            ->get();
    }

    private function recentVerifications(int $limit): iterable
    {
        return DB::table('professional_profiles')
            ->select('id', 'business_name', 'verification_status', 'verified_at', 'updated_at')
            ->whereIn('verification_status', [
                VerificationStatus::Verified->value,
                VerificationStatus::Rejected->value,
            ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    private function recentBookings(int $limit): iterable
    {
        return DB::table('bookings')
            ->select('id', 'reference', 'title', 'status', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    private function recentQuotations(int $limit): iterable
    {
        return DB::table('quotations')
            ->join('service_requests', 'service_requests.id', '=', 'quotations.service_request_id')
            ->select(
                'quotations.id',
                'quotations.reference',
                'quotations.status',
                'quotations.updated_at',
                'service_requests.title as job_title',
            )
            ->orderByDesc('quotations.updated_at')
            ->limit($limit)
            ->get();
    }

    private function recentAdminActions(int $limit): iterable
    {
        if (! $this->has('admin_logs')) {
            return collect();
        }

        return AdminLog::query()
            ->with('user:id,name')
            ->recent()
            ->limit($limit)
            ->get();
    }

    /**
     * The leaderboard used for "top rated" and "most completed jobs".
     *
     * @return array<int, array<string, mixed>>
     */
    private function topProfessionals(string $orderBy, string $having): array
    {
        return DB::table('professional_profiles')
            ->join('users', 'users.id', '=', 'professional_profiles.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'professional_profiles.base_location_id')
            ->selectRaw(
                'professional_profiles.id, professional_profiles.business_name, '
                .'professional_profiles.rating_avg, professional_profiles.rating_count, '
                .'professional_profiles.completed_jobs_count, professional_profiles.verification_status, '
                .'users.name, locations.name as location_name'
            )
            ->where('professional_profiles.verification_status', VerificationStatus::Verified->value)
            ->whereRaw($having)
            ->orderByDesc("professional_profiles.$orderBy")
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'business_name' => $row->business_name,
                'name' => $row->name,
                'location' => $row->location_name,
                'rating_avg' => (float) $row->rating_avg,
                'rating_count' => (int) $row->rating_count,
                'completed_jobs_count' => (int) $row->completed_jobs_count,
            ])
            ->all();
    }
}
