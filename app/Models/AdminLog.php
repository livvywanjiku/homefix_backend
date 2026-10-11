<?php

namespace App\Models;

use App\Enums\AdminAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the administrative audit trail (spec §20).
 *
 * Written through {@see \App\Services\Admin\AuditLogger} rather than directly,
 * so that every entry carries the same facts — who, from where, what changed —
 * and a new call site cannot omit one of them by accident.
 *
 * There is no `update` path. The trail is a record of what happened.
 */
#[Fillable([
    'user_id',
    'action',
    'description',
    'subject_type',
    'subject_id',
    'ip_address',
    'user_agent',
    'metadata',
])]
class AdminLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdminAction::class,
            'metadata' => 'array',
        ];
    }

    /* ── Relations ─────────────────────────────────────────────────────── */

    /**
     * The administrator who acted, when the account still exists.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What was acted upon: a user, a profile, a service, a booking.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /* ── Scopes ────────────────────────────────────────────────────────── */

    /**
     * Newest first, which is the only order an audit trail is read in.
     *
     * @param  Builder<AdminLog>  $query
     * @return Builder<AdminLog>
     */
    #[Scope]
    protected function recent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<AdminLog>  $query
     * @return Builder<AdminLog>
     */
    #[Scope]
    protected function forAction(Builder $query, AdminAction $action): Builder
    {
        return $query->where('action', $action->value);
    }
}
