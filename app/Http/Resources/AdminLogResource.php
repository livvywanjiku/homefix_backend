<?php

namespace App\Http\Resources;

use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the audit trail (spec §20).
 *
 * The actor's name is included and their email is not: the trail answers "who
 * did this" for an administrator reading it, and the account is one click away
 * from the id. Copying contact details into every row would put them in every
 * export of the log as well.
 *
 * @mixin AdminLog
 */
class AdminLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            // The colour the console paints the entry with, decided by the enum
            // rather than by the page — a moderation screen and an audit screen
            // showing the same act in two colours is how a UI stops meaning
            // anything.
            'tone' => $this->action->tone(),

            'description' => $this->description,

            'subject' => $this->subject_type === null ? null : [
                'type' => class_basename((string) $this->subject_type),
                'id' => $this->subject_id,
            ],

            'actor' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),

            /*
             * Kept as sent. The payload is whatever the writer recorded — a
             * before/after pair, a role name, a reason — and reshaping it here
             * would mean the trail showed something other than what was written.
             */
            'metadata' => $this->metadata,

            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
