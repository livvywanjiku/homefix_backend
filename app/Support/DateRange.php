<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The window a dashboard figure is reported over.
 *
 * Every statistic on the dashboard is "this many, over this period, compared
 * with the one before it", so the period is a first-class object rather than a
 * pair of dates threaded through a dozen method signatures. It also carries its
 * own predecessor, which is what makes the "+12.5% vs previous month" line
 * possible without each caller working the arithmetic out for itself.
 */
final readonly class DateRange
{
    /** The windows the dashboard's date filter offers. */
    public const PRESETS = [
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'last_90_days',
        'this_year',
        'custom',
    ];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset = 'last_30_days',
    ) {}

    /**
     * Reads the window out of the query string, falling back to the last 30
     * days.
     *
     * An unknown preset falls back rather than erroring: this is a filter on a
     * read-only dashboard, and a stale bookmark is not worth a 422.
     */
    public static function fromRequest(Request $request): self
    {
        $preset = $request->query('range');
        $preset = is_string($preset) && in_array($preset, self::PRESETS, true)
            ? $preset
            : 'last_30_days';

        $now = CarbonImmutable::now();

        return match ($preset) {
            'today' => new self($now->startOfDay(), $now->endOfDay(), $preset),
            'yesterday' => new self(
                $now->subDay()->startOfDay(),
                $now->subDay()->endOfDay(),
                $preset,
            ),
            'last_7_days' => new self($now->subDays(6)->startOfDay(), $now->endOfDay(), $preset),
            'last_90_days' => new self($now->subDays(89)->startOfDay(), $now->endOfDay(), $preset),
            'this_year' => new self($now->startOfYear(), $now->endOfDay(), $preset),
            'custom' => self::custom($request, $now),
            default => new self($now->subDays(29)->startOfDay(), $now->endOfDay(), 'last_30_days'),
        };
    }

    /**
     * A caller-supplied window.
     *
     * The dates arrive from a date picker, so they are parsed defensively and
     * the window is capped at a year: an unbounded range would let one request
     * ask the database to aggregate everything it has ever held, which is the
     * opposite of the dashboard's performance requirement.
     */
    private static function custom(Request $request, CarbonImmutable $now): self
    {
        $from = self::parse($request->query('from'), $now->subDays(29)->startOfDay());
        $to = self::parse($request->query('to'), $now->endOfDay());

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->subYear()->startOfDay();
        }

        return new self($from, $to, 'custom');
    }

    private static function parse(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * The window of the same length immediately before this one.
     *
     * Same *length*, not "the previous calendar month": comparing a 31-day
     * March against a 28-day February would report a fall in every metric that
     * has not actually fallen.
     */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to);

        return new self(
            $this->from->subSeconds($length + 1),
            $this->from->subSecond(),
            $this->preset,
        );
    }

    /** How many days the window covers, inclusive of both ends. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The bucket size a trend series should use for this window.
     *
     * Short windows read day by day; a year of daily points is a chart nobody
     * can read, so it collapses to months. Weekly sits between the two.
     */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 92 => 'day',
            $this->days() <= 366 => 'week',
            default => 'month',
        };
    }

    /**
     * Every bucket boundary in the window, as [start, end] pairs.
     *
     * Generated here rather than by the database so that a period with no rows
     * still appears on the chart as a zero rather than as a missing point —
     * a revenue line that skips a quiet week reads as a broken chart.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = match ($this->granularity()) {
            'week' => $this->from->startOfWeek(),
            'month' => $this->from->startOfMonth(),
            default => $this->from->startOfDay(),
        };

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $end = match ($this->granularity()) {
                'week' => $cursor->endOfWeek(),
                'month' => $cursor->endOfMonth(),
                default => $cursor->endOfDay(),
            };

            $buckets[] = [
                'start' => $cursor,
                'end' => $end,
                'label' => $this->labelFor($cursor),
            ];

            $cursor = match ($this->granularity()) {
                'week' => $cursor->addWeek()->startOfWeek(),
                'month' => $cursor->addMonth()->startOfMonth(),
                default => $cursor->addDay()->startOfDay(),
            };
        }

        return $buckets;
    }

    /**
     * Which bucket a date belongs in, or null when it falls outside the window.
     *
     * Bucket edges can extend past the window (a week that began last month), so
     * the lookup is by containment rather than by arithmetic on an index — the
     * two disagree exactly at the boundaries.
     */
    public function bucketIndex(CarbonImmutable $moment): ?int
    {
        foreach ($this->buckets() as $index => $bucket) {
            if ($moment->betweenIncluded($bucket['start'], $bucket['end'])) {
                return $index;
            }
        }

        return null;
    }

    private function labelFor(CarbonImmutable $moment): string
    {
        return match ($this->granularity()) {
            'month' => $moment->format('M Y'),
            'week' => 'w/c '.$moment->format('j M'),
            default => $moment->format('j M'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'granularity' => $this->granularity(),
            'days' => $this->days(),
        ];
    }
}
