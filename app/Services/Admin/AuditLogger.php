<?php

namespace App\Services\Admin;

use App\Enums\AdminAction;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the administrative audit trail (spec §20).
 *
 * Every mutation made from the admin area goes through here rather than
 * `AdminLog::create()`, so each entry carries the same facts: who acted, from
 * which address, on what, and what changed. A call site cannot forget the ip
 * address, and the acting account is read from the authenticated session rather
 * than passed in — an audit entry naming whoever the caller *said* they were
 * would be worthless.
 *
 * Writes are not transactional with the change they describe. If the change
 * rolls back, an entry may survive that records an attempt; that is the safer
 * direction of error for a trail whose purpose is to show that something was
 * tried.
 */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * Record an administrative act.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AdminAction $action,
        ?Model $subject = null,
        ?string $description = null,
        array $metadata = [],
    ): AdminLog {
        $request = $this->request ?? $this->currentRequest();

        return AdminLog::create([
            'user_id' => $this->actor()?->id,
            'action' => $action->value,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $this->truncate($request?->userAgent()),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Record a change as a before/after pair.
     *
     * The keys that actually moved are computed here rather than left to the
     * caller, so an entry never claims a field changed when it did not — which
     * is the failure mode that makes an audit trail untrustworthy.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChange(
        AdminAction $action,
        ?Model $subject,
        array $before,
        array $after,
        ?string $description = null,
    ): AdminLog {
        $changes = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ($previous !== $value) {
                $changes[$key] = ['from' => $previous, 'to' => $value];
            }
        }

        return $this->record($action, $subject, $description, ['changes' => $changes]);
    }

    /**
     * The authenticated account, when there is one.
     *
     * Null in console and queue contexts, which is why the column is nullable:
     * a scheduled change is still a change, and recording it with no actor is
     * more honest than attributing it to whoever happened to run the command
     * last.
     */
    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function currentRequest(): ?Request
    {
        if (app()->runningInConsole()) {
            return null;
        }

        return app()->bound('request') ? app('request') : null;
    }

    /** The column is 255 wide; a long UA is trimmed rather than rejected. */
    private function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
