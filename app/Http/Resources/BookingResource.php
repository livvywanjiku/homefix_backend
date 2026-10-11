<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A piece of agreed work (spec §10).
 *
 * Read by the two parties to it and by administrators. The address and the
 * coordinates are sent without a policy check on the fields, unlike a job
 * posting: everybody who can reach a booking has already been established as
 * entitled to turn up at it, which is the whole reason the booking exists.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            /*
             * The state machine, sent to the client rather than reconstructed
             * there. The controls a booking screen shows are exactly the
             * transitions the server would permit, and a browser guessing at them
             * from the status name would drift the moment the enum changes.
             *
             * These are what *could* happen next; who may do it is a separate
             * question, answered by the `can_*` flags below.
             */
            'allowed_transitions' => array_map(
                fn (BookingStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                $this->status->allowedTransitions(),
            ),

            'is_active' => $this->isActive(),
            'is_finished' => $this->isFinished(),
            'is_reviewable' => $this->isReviewable(),

            'title' => $this->title,
            'description' => $this->description,

            'address' => $this->address,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,

            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'scheduled_time_slot' => $this->scheduled_time_slot?->value,
            'scheduled_time_slot_label' => $this->scheduled_time_slot?->label(),

            /*
             * The agreed price, copied from the accepted quotation when the
             * booking was created. Read from the booking rather than the
             * quotation on purpose — a booking is what both sides agreed to, and
             * it must not move if the quotation behind it is ever edited.
             */
            'labour_cents' => $this->labour_cents,
            'materials_cents' => $this->materials_cents,
            'transport_cents' => $this->transport_cents,
            'other_cents' => $this->other_cents,
            'total_cents' => $this->total_cents,

            'agreed_at' => $this->agreed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'service' => ServiceResource::make($this->whenLoaded('service')),
            'location' => LocationResource::make($this->whenLoaded('location')),

            /*
             * The two parties, by name only. Contact details that would let
             * either side take the arrangement off the platform arrive with the
             * messaging and payment flows; until then the booking is the record
             * of the agreement, not a channel.
             */
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'avatar_url' => $this->customer->avatar_path,
            ]),

            'professional' => $this->whenLoaded('professional', fn (): array => [
                'id' => $this->professional->id,
                'name' => $this->professional->name,
                'avatar_url' => $this->professional->avatar_path,
                'business_name' => $this->professional->professionalProfile?->business_name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
