<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reporting interface (spec §20).
 *
 * Reports are run against the live tables with the filters the reader chose.
 * Nothing is cached and nothing is pre-computed: a report that answered from a
 * snapshot would be answering a different question than the one asked.
 *
 * CSV is streamed rather than assembled in memory, because the whole point of a
 * report is that it may be large. The PDF route is a print view rather than a
 * generated binary — see {@see printable()}.
 */
class ReportController extends Controller
{
    /**
     * The reports the interface offers, with the filters each one understands.
     *
     * Derived from one definition so the picker, the CSV writer and the print
     * view cannot disagree about which columns a report has.
     *
     * @var array<string, array{label: string, description: string, filters: array<int, string>}>
     */
    private const REPORTS = [
        'bookings' => [
            'label' => 'Bookings',
            'description' => 'Every booking in the period, with its state and value.',
            'filters' => ['status', 'service', 'location'],
        ],
        'service_requests' => [
            'label' => 'Service requests',
            'description' => 'Jobs posted by customers, and how many quotations each drew.',
            'filters' => ['status', 'service', 'location'],
        ],
        'users' => [
            'label' => 'Users',
            'description' => 'Registered accounts and their current standing.',
            'filters' => ['role', 'status'],
        ],
        'professionals' => [
            'label' => 'Professionals',
            'description' => 'Professional profiles, verification and trading figures.',
            'filters' => ['status', 'location'],
        ],
    ];

    /**
     * What the reporting screen may ask for.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $reports = [];

        foreach (self::REPORTS as $key => $definition) {
            $reports[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'filters' => $definition['filters'],
                // A report whose table does not exist is listed as unavailable
                // rather than offered and then failing.
                'available' => $this->isAvailable($key),
            ];
        }

        return response()->json([
            'reports' => $reports,
            'formats' => ['csv', 'print'],
            'ranges' => DateRange::PRESETS,
        ]);
    }

    /**
     * Take a report out of the building.
     *
     * Separate from the preview so the two can be gated by different
     * permissions: reading a summary on screen and downloading the whole dataset
     * are different privileges, and the CSV is the one that leaves.
     */
    public function export(Request $request, string $report): Response|StreamedResponse
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404, 'Unknown report.');
        abort_unless($this->isAvailable($report), 422, 'That report is not available yet.');

        $range = DateRange::fromRequest($request);

        return $request->query('format') === 'print'
            ? $this->printable($report, $range, $request)
            : $this->csv($report, $range, $request);
    }

    /**
     * Run a report: JSON for the preview table, CSV for the download, and the
     * print view for a PDF.
     */
    public function show(Request $request, string $report): Response|StreamedResponse|\Illuminate\Http\JsonResponse
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404, 'Unknown report.');
        abort_unless($this->isAvailable($report), 422, 'That report is not available yet.');

        $range = DateRange::fromRequest($request);
        $format = (string) $request->query('format', 'json');

        /*
         * The CSV is the format that leaves the building, so it is re-checked
         * here rather than relying on the route. This action is behind
         * `view_reports` alone, and without this guard `?format=csv` would hand
         * the whole dataset to an administrator who was deliberately not given
         * `export_reports` — the one distinction the separate export route
         * exists to draw.
         */
        if ($format === 'csv') {
            abort_unless(
                $request->user()?->hasPermissionTo(Permission::ExportReports->value) === true,
                403,
                'Downloading a report requires the export_reports permission.',
            );
        }

        return match ($format) {
            'csv' => $this->csv($report, $range, $request),
            'print' => $this->printable($report, $range, $request),
            default => $this->preview($report, $range, $request),
        };
    }

    /**
     * The on-screen preview: the first page of rows, and whether there are more.
     *
     * One row beyond the page is fetched rather than a second COUNT, so the
     * "showing 200 of many" note costs nothing extra to produce.
     */
    private function preview(string $report, DateRange $range, Request $request): \Illuminate\Http\JsonResponse
    {
        $page = 200;
        $rows = $this->rows($report, $range, $request, limit: $page + 1);
        $truncated = $rows->count() > $page;

        return response()->json([
            'report' => $report,
            'label' => self::REPORTS[$report]['label'],
            'range' => $range->toArray(),
            'columns' => $this->columns($report),
            'rows' => $rows->take($page)->values(),
            'truncated' => $truncated,
            'preview_limit' => $page,
        ]);
    }

    /**
     * Whether the tables a report reads exist.
     */
    private function isAvailable(string $report): bool
    {
        $tables = match ($report) {
            'bookings' => ['bookings'],
            'service_requests' => ['service_requests'],
            'users' => ['users'],
            'professionals' => ['professional_profiles'],
            default => [],
        };

        foreach ($tables as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The column headings, which are also the CSV header row.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function columns(string $report): array
    {
        return match ($report) {
            'bookings' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'created_at', 'label' => 'Created'],
                ['key' => 'title', 'label' => 'Job'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'professional', 'label' => 'Professional'],
                ['key' => 'service', 'label' => 'Service'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'agreed_total', 'label' => 'Agreed total'],
            ],
            'service_requests' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'created_at', 'label' => 'Posted'],
                ['key' => 'title', 'label' => 'Job'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'service', 'label' => 'Service'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'quotes_count', 'label' => 'Quotes'],
            ],
            'users' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Registered'],
            ],
            'professionals' => [
                ['key' => 'business_name', 'label' => 'Business'],
                ['key' => 'name', 'label' => 'Account'],
                ['key' => 'location', 'label' => 'Base'],
                ['key' => 'verification_status', 'label' => 'Verification'],
                ['key' => 'rating_avg', 'label' => 'Rating'],
                ['key' => 'rating_count', 'label' => 'Reviews'],
                ['key' => 'completed_jobs_count', 'label' => 'Jobs completed'],
                ['key' => 'created_at', 'label' => 'Joined'],
            ],
            default => [],
        };
    }

    /**
     * The filtered rows.
     *
     * A limit is applied only for the JSON preview; the CSV writer walks a
     * cursor so an export of every row still uses constant memory.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function rows(string $report, DateRange $range, Request $request, ?int $limit = null): \Illuminate\Support\Collection
    {
        $query = $this->query($report, $range, $request);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (object $row): array => $this->present($report, $row));
    }

    /**
     * The filtered query behind every format.
     *
     * Filters are applied to the query rather than after it: a report narrowed
     * to one town should read one town's rows, not read every row and discard
     * most of them.
     */
    private function query(string $report, DateRange $range, Request $request): Builder
    {
        $query = match ($report) {
            'bookings' => DB::table('bookings')
                ->leftJoin('services', 'services.id', '=', 'bookings.service_id')
                ->leftJoin('locations', 'locations.id', '=', 'bookings.location_id')
                ->leftJoin('users as customers', 'customers.id', '=', 'bookings.customer_id')
                ->leftJoin('users as professionals', 'professionals.id', '=', 'bookings.professional_id')
                ->selectRaw(
                    'bookings.reference, bookings.created_at, bookings.title, bookings.status, '
                    .'bookings.total_cents as agreed_total, customers.name as customer, '
                    .'professionals.name as professional, services.name as service, locations.name as location'
                ),

            'service_requests' => DB::table('service_requests')
                ->leftJoin('services', 'services.id', '=', 'service_requests.service_id')
                ->leftJoin('locations', 'locations.id', '=', 'service_requests.location_id')
                ->leftJoin('users as customers', 'customers.id', '=', 'service_requests.customer_id')
                ->selectRaw(
                    'service_requests.reference, service_requests.created_at, service_requests.title, '
                    .'service_requests.status, service_requests.quotes_count, customers.name as customer, '
                    .'services.name as service, locations.name as location'
                ),

            'users' => DB::table('users')
                ->leftJoin('model_has_roles', function ($join): void {
                    $join->on('model_has_roles.model_id', '=', 'users.id')
                        ->where('model_has_roles.model_type', '=', User::class);
                })
                ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->selectRaw(
                    'users.id, users.name, users.email, users.phone, users.status, '
                    .'users.created_at, roles.name as role'
                ),

            'professionals' => DB::table('professional_profiles')
                ->leftJoin('users', 'users.id', '=', 'professional_profiles.user_id')
                ->leftJoin('locations', 'locations.id', '=', 'professional_profiles.base_location_id')
                ->selectRaw(
                    'professional_profiles.business_name, professional_profiles.verification_status, '
                    .'professional_profiles.rating_avg, professional_profiles.rating_count, '
                    .'professional_profiles.completed_jobs_count, professional_profiles.created_at, '
                    .'users.name, locations.name as location'
                ),

            default => DB::table('users')->selectRaw('users.*'),
        };

        $dateColumn = match ($report) {
            'professionals' => 'professional_profiles.created_at',
            'service_requests' => 'service_requests.created_at',
            'bookings' => 'bookings.created_at',
            default => 'users.created_at',
        };

        $query->whereBetween($dateColumn, [$range->from, $range->to])->orderByDesc($dateColumn);

        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $column = match ($report) {
                'professionals' => 'professional_profiles.verification_status',
                default => null,
            };

            if ($column !== null && in_array($status, array_map(
                fn (VerificationStatus $s): string => $s->value,
                VerificationStatus::cases(),
            ), true)) {
                $query->where($column, $status);
            } elseif ($report === 'bookings' && in_array($status, BookingStatus::values(), true)) {
                $query->where('bookings.status', $status);
            } elseif ($report === 'service_requests' && in_array($status, ServiceRequestStatus::values(), true)) {
                $query->where('service_requests.status', $status);
            } elseif ($report === 'users') {
                $query->where('users.status', $status);
            }
        }

        $role = $request->query('role');

        if ($report === 'users' && is_string($role) && $role !== '') {
            $query->where('roles.name', $role);
        }

        $serviceId = $request->integer('service_id');

        if ($serviceId > 0 && in_array($report, ['bookings', 'service_requests'], true)) {
            $query->where($report.'.service_id', $serviceId);
        }

        $locationId = $request->integer('location_id');

        if ($locationId > 0) {
            $column = match ($report) {
                'professionals' => 'professional_profiles.base_location_id',
                'bookings' => 'bookings.location_id',
                'service_requests' => 'service_requests.location_id',
                default => null,
            };

            if ($column !== null) {
                $query->where($column, $locationId);
            }
        }

        return $query;
    }

    /**
     * Flattens a row for output.
     *
     * Enum values are rendered as their display labels, because a report is read
     * by a person: a sheet of `in_progress` and `bank_transfer` is a database
     * dump, not a report.
     *
     * @return array<string, mixed>
     */
    private function present(string $report, object $row): array
    {
        $data = (array) $row;

        if (isset($data['status']) && $report === 'bookings') {
            $data['status'] = BookingStatus::tryFrom((string) $data['status'])?->label()
                ?? (string) $data['status'];
        }

        if (isset($data['status']) && $report === 'service_requests') {
            $data['status'] = ServiceRequestStatus::tryFrom((string) $data['status'])?->label()
                ?? (string) $data['status'];
        }

        if (isset($data['verification_status'])) {
            $data['verification_status'] = VerificationStatus::tryFrom((string) $data['verification_status'])?->label()
                ?? (string) $data['verification_status'];
        }

        if (isset($data['role']) && is_string($data['role'])) {
            $data['role'] = UserRole::tryFrom($data['role'])?->label() ?? $data['role'];
        }

        if (isset($data['status']) && $report === 'users') {
            $data['status'] = ucfirst((string) $data['status']);
        }

        if (array_key_exists('agreed_total', $data)) {
            $data['agreed_total'] = $data['agreed_total'] === null
                ? null
                : number_format(((int) $data['agreed_total']) / 100, 2, '.', '');
        }

        return $data;
    }

    /**
     * The CSV download.
     *
     * Streamed with a cursor, so exporting a year of bookings holds one row in
     * memory rather than all of them. The BOM is deliberate: without it Excel
     * reads a UTF-8 file as the system codepage and mangles every accented name.
     */
    private function csv(string $report, DateRange $range, Request $request): StreamedResponse
    {
        $columns = $this->columns($report);
        $filename = sprintf('%s-%s.csv', $report, $range->from->format('Y-m-d'));

        return response()->streamDownload(function () use ($report, $range, $request, $columns): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($columns, 'label'));

            foreach ($this->query($report, $range, $request)->cursor() as $row) {
                $presented = $this->present($report, $row);

                fputcsv($out, array_map(
                    fn (array $column): string => (string) ($presented[$column['key']] ?? ''),
                    $columns,
                ));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * A print view, which is how this platform produces a PDF.
     *
     * No PDF library is installed, and inventing a broken "Download PDF" button
     * would be worse than not having one. This returns a self-contained
     * print-styled document that the browser's own "Save as PDF" turns into an
     * exact PDF — real output, from real data, with no dependency added for it.
     */
    private function printable(string $report, DateRange $range, Request $request): Response
    {
        $columns = $this->columns($report);

        // A print view is assembled in memory before it is sent, so it is the
        // one format that has to be bounded. The CSV export is the unbounded
        // one, and it streams.
        $rows = $this->rows($report, $range, $request, limit: 5000);
        $label = self::REPORTS[$report]['label'];

        $html = view('reports.print', [
            'title' => $label,
            'range' => $range,
            'columns' => $columns,
            'rows' => $rows,
            'generatedAt' => now(),
            'generatedBy' => $request->user()?->name,
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
