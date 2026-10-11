<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The system-health panel (spec §20).
 *
 * Every line here is the result of a check that actually ran. None of it is
 * hardcoded, and — the part that matters — a component that is not configured
 * reports exactly that rather than reporting "operational". An integrations
 * panel that shows green ticks for services nobody has set up is worse than no
 * panel at all, because it is confidently wrong.
 *
 * The checks are cheap and side-effect free: a connection read, a config read,
 * and one small write-and-delete on the storage disk.
 */
class SystemHealth
{
    public const OPERATIONAL = 'operational';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public const NOT_CONFIGURED = 'not_configured';

    /**
     * @return array<int, array{key: string, label: string, status: string, detail: string}>
     */
    public function report(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storage(),
            $this->notifications(),
            $this->payments(),
            $this->realtime(),
        ];
    }

    /**
     * A single overall status, for the summary badge.
     *
     * Anything not configured is excluded from the verdict: an unconfigured
     * optional integration should not make the platform look unwell, and
     * anything down makes it look unwell whatever else is fine.
     */
    public function overall(): string
    {
        $statuses = array_column($this->report(), 'status');

        if (in_array(self::DOWN, $statuses, true)) {
            return self::DOWN;
        }

        return in_array(self::DEGRADED, $statuses, true) ? self::DEGRADED : self::OPERATIONAL;
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function database(): array
    {
        $started = microtime(true);

        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');

            $latency = (int) round((microtime(true) - $started) * 1000);

            return $this->line(
                'database',
                'Database',
                $latency > 500 ? self::DEGRADED : self::OPERATIONAL,
                sprintf('%s · %d ms', DB::connection()->getDriverName(), $latency),
            );
        } catch (Throwable $exception) {
            return $this->line('database', 'Database', self::DOWN, $exception->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function cache(): array
    {
        $store = (string) config('cache.default');

        if ($store === 'array') {
            return $this->line('cache', 'Cache', self::NOT_CONFIGURED, 'array (per-request only)');
        }

        try {
            $key = 'health:'.bin2hex(random_bytes(6));

            Cache::put($key, 'ok', 5);
            $value = Cache::pull($key);

            return $value === 'ok'
                ? $this->line('cache', 'Cache', self::OPERATIONAL, $store)
                : $this->line('cache', 'Cache', self::DEGRADED, "$store did not return what it stored");
        } catch (Throwable $exception) {
            return $this->line('cache', 'Cache', self::DOWN, $exception->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');

        /*
         * `sync` runs every job inside the request that dispatched it. That is
         * fine in development and wrong in production — nothing is retried and
         * nothing survives a failure — so it is reported as unconfigured rather
         * than healthy.
         */
        if (in_array($driver, ['sync', 'null'], true)) {
            return $this->line('queue', 'Queue', self::NOT_CONFIGURED, "$driver (no background worker)");
        }

        if ($driver === 'database') {
            try {
                $pending = DB::table('jobs')->count();

                return $this->line(
                    'queue',
                    'Queue',
                    $pending > 1000 ? self::DEGRADED : self::OPERATIONAL,
                    "database · $pending waiting",
                );
            } catch (Throwable $exception) {
                return $this->line('queue', 'Queue', self::DOWN, $exception->getMessage());
            }
        }

        return $this->line('queue', 'Queue', self::OPERATIONAL, $driver);
    }

    /**
     * Proves the disk is writable rather than assuming it.
     *
     * A storage failure is the one that hides until the day somebody uploads a
     * portfolio photo, so the check writes a byte and removes it again.
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function storage(): array
    {
        $disk = (string) config('filesystems.default');

        try {
            $path = 'health/'.bin2hex(random_bytes(6)).'.txt';

            Storage::disk($disk)->put($path, 'ok');
            $ok = Storage::disk($disk)->get($path) === 'ok';
            Storage::disk($disk)->delete($path);

            return $ok
                ? $this->line('storage', 'Storage', self::OPERATIONAL, $disk)
                : $this->line('storage', 'Storage', self::DEGRADED, "$disk lost what it stored");
        } catch (Throwable $exception) {
            return $this->line('storage', 'Storage', self::DOWN, $exception->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function notifications(): array
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->line(
                'notifications',
                'Email delivery',
                self::NOT_CONFIGURED,
                "$mailer (messages are not delivered)",
            );
        }

        return $this->line('notifications', 'Email delivery', self::OPERATIONAL, $mailer);
    }

    /**
     * The payment gateway.
     *
     * Reports what is configured, not what is hoped for. With no driver and no
     * Daraja credentials this is "not configured" — which is the truth until
     * the payments slice ships and the keys are supplied.
     *
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function payments(): array
    {
        $driver = config('payments.driver');

        if (! is_string($driver) || $driver === '') {
            return $this->line(
                'payments',
                'Payments',
                self::NOT_CONFIGURED,
                'no gateway configured',
            );
        }

        if ($driver === 'mpesa') {
            $key = config('payments.mpesa.consumer_key');
            $secret = config('payments.mpesa.consumer_secret');
            $callback = config('payments.mpesa.callback_url');

            if (blank($key) || blank($secret) || blank($callback)) {
                return $this->line(
                    'payments',
                    'Payments',
                    self::DEGRADED,
                    'M-Pesa selected but credentials or callback URL are missing',
                );
            }
        }

        return $this->line('payments', 'Payments', self::OPERATIONAL, $driver);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function realtime(): array
    {
        $connection = (string) config('broadcasting.default');

        if (in_array($connection, ['null', 'log'], true)) {
            return $this->line(
                'realtime',
                'Real-time messaging',
                self::NOT_CONFIGURED,
                "$connection (live updates are off)",
            );
        }

        return $this->line('realtime', 'Real-time messaging', self::OPERATIONAL, $connection);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function line(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
