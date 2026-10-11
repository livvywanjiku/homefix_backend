<?php

namespace App\Http\Resources;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job posting (spec §8).
 *
 * Two audiences read this: the customer who owns it, and professionals deciding
 * whether to quote. The split is deliberate — a professional browsing the board
 * gets the job, the town and the budget, but not the street address or the
 * access notes, which are released only to someone entitled to turn up.
 *
 * @mixin ServiceRequest
 */
class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $maySeeContact = $viewer !== null
            && $viewer->can('viewContactDetails', $this->resource);

        return [
            'id' => $this->id,

            // The handle the customer reads out on the phone.
            'reference' => $this->reference,

            'title' => $this->title,
            'description' => $this->description,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'urgency' => $this->urgency->value,
            'urgency_label' => $this->urgency->label(),

            /*
             * State the client cannot work out for itself. `status` alone is not
             * enough: a request can still read "open" the moment before the
             * expiry sweep runs, so the deadline is evaluated here and the
             * resulting capabilities travel with the row.
             */
            'is_expired' => $this->hasExpired(),
            'accepts_quotations' => $this->acceptsQuotations(),
            'is_editable' => $this->isEditable(),
            'is_cancellable' => $this->isCancellable(),

            'preferred_date' => $this->preferred_date?->toDateString(),
            'preferred_time_slot' => $this->preferred_time_slot?->value,
            'preferred_time_slot_label' => $this->preferred_time_slot?->label(),

            'budget_min_cents' => $this->budget_min_cents,
            'budget_max_cents' => $this->budget_max_cents,

            'quotes_count' => $this->quotes_count,
            'expires_at' => $this->expires_at?->toIso8601String(),

            /*
             * How far this job is from the professional reading the board. Set by
             * the controller after pagination (it is computed in PHP, see
             * App\Support\Geo), and absent — not zero — when the distance cannot
             * be known, so the client renders nothing rather than "0 km away".
             */
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn (): ?float => $this->distance_km === null ? null : round((float) $this->distance_km, 1),
            ),

            // Withheld from the open board: see the class note.
            'address' => $this->when($maySeeContact, $this->address),
            'instructions' => $this->when($maySeeContact, $this->instructions),
            'latitude' => $this->when($maySeeContact, $this->latitude === null ? null : (float) $this->latitude),
            'longitude' => $this->when($maySeeContact, $this->longitude === null ? null : (float) $this->longitude),

            'service' => ServiceResource::make($this->whenLoaded('service')),
            'location' => LocationResource::make($this->whenLoaded('location')),
            'images' => ServiceRequestImageResource::collection($this->whenLoaded('images')),

            /*
             * The offers this job attracted, present only where the relation was
             * eager-loaded. The customer reads their own through the dedicated
             * quotations endpoint and the open board never loads them — a
             * professional browsing for work must not see what their competitors
             * are bidding. The one caller that does load them is the
             * administrator's view of a single posting, which is what makes an
             * unmet quotation count legible.
             */
            'quotations' => QuotationResource::collection($this->whenLoaded('quotations')),

            /*
             * Who posted it. A professional needs a name to address a quote to;
             * nobody needs the customer's email or phone number at this stage —
             * the platform's whole purpose is to keep that exchange inside the
             * request and messaging flows until a booking exists.
             */
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'avatar_url' => $this->customer->avatar_path,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
