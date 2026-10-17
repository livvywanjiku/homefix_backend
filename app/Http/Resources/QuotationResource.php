<?php

namespace App\Http\Resources;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A professional's price offer (spec §9).
 *
 * Read by both sides, from opposite ends: the customer comparing four offers on
 * their job, and the professional tracking what they have bid on. Both need the
 * same figures, so there is one resource rather than two that could disagree.
 *
 * The `can_be_*` flags are answered by the policy for *this* viewer rather than
 * derived from `status` on the client. The same row is actionable by one party
 * and read-only to the other, and a browser working that out from the status
 * alone would get it wrong in both directions.
 *
 * @mixin Quotation
 */
class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,

            // The handle both sides read out when discussing the price.
            'reference' => $this->reference,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            /*
             * State the client cannot work out for itself. A quotation can still
             * read "pending" the moment before the expiry sweep runs, and the
             * job it belongs to can close without the quotation changing at all —
             * so both are evaluated here and travel with the row.
             */
            'is_live' => $this->isLive(),
            'has_expired' => $this->hasExpired(),
            'is_acceptable' => $this->isAcceptable(),

            'can_be_accepted' => $viewer !== null && $viewer->can('accept', $this->resource),
            'can_be_rejected' => $viewer !== null && $viewer->can('reject', $this->resource),
            'can_be_edited' => $viewer !== null && $viewer->can('update', $this->resource),
            'can_be_withdrawn' => $viewer !== null && $viewer->can('withdraw', $this->resource),

            'message' => $this->message,

            'estimated_days' => $this->estimated_days,
            'available_from' => $this->available_from?->toDateString(),
            'valid_until' => $this->valid_until?->toIso8601String(),

            /*
             * The itemised price. The four category totals and the grand total
             * are the cached sums of `items` — see Quotation::recalculateTotals().
             * They are sent alongside the lines so a comparison table can sort
             * and total without walking every breakdown.
             */
            'labour_cents' => $this->labour_cents,
            'materials_cents' => $this->materials_cents,
            'transport_cents' => $this->transport_cents,
            'other_cents' => $this->other_cents,
            'total_cents' => $this->total_cents,

            'items' => QuotationItemResource::collection($this->whenLoaded('items')),

            /*
             * Who is offering. The business name and track record, not the
             * account: a customer choosing between four tradespeople is choosing
             * between businesses, and the contact details that would let them go
             * around the platform stay behind the booking and messaging flows.
             */
            'professional' => $this->whenLoaded('professional', fn (): array => [
                'id' => $this->professional->id,
                'name' => $this->professional->name,
                'avatar_url' => $this->professional->avatar_url,
                'business_name' => $this->professional->professionalProfile?->business_name,
                'rating_avg' => $this->professional->professionalProfile === null
                    ? null
                    : (float) $this->professional->professionalProfile->rating_avg,
                'rating_count' => $this->professional->professionalProfile?->rating_count,
                'completed_jobs_count' => $this->professional->professionalProfile?->completed_jobs_count,
            ]),

            /*
             * The counterparty's phone number, and only after the job has been
             * awarded. Before that the customer is comparing and the
             * professional is bidding; giving either side the other's number
             * then would let the arrangement leave the platform before it
             * exists. Once accepted, the booking is the agreement and the two
             * parties need to arrange the visit.
             */
            'contact' => $this->contactDetails($viewer),

            /*
             * The job it is for. A professional reading their own list needs to
             * know which posting each price belongs to, and what became of it —
             * an offer that looks pending on a request that was cancelled last
             * week is a very different thing from one awaiting a decision.
             */
            'service_request' => $this->whenLoaded('serviceRequest', fn (): array => [
                'id' => $this->serviceRequest->id,
                'reference' => $this->serviceRequest->reference,
                'title' => $this->serviceRequest->title,
                'status' => $this->serviceRequest->status->value,
                'status_label' => $this->serviceRequest->status->label(),
            ]),

            /*
             * Present once the offer has been taken. The client uses the id to
             * send the customer straight to the booking rather than leaving them
             * on a comparison table for a decision already made.
             */
            'booking' => BookingResource::make($this->whenLoaded('booking')),

            // When the customer decided, whichever way they decided.
            'decided_at' => ($this->accepted_at ?? $this->rejected_at ?? $this->withdrawn_at)?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The other party's number, once this offer has been accepted.
     *
     * Returns null for a pending offer, for an administrator, and for any
     * account that is neither the customer who posted the job nor the
     * professional who quoted it. That keeps the existing privacy guarantee —
     * contact details never appear in discovery or while a job is still being
     * priced — while giving the two parties what they need to arrange the work.
     *
     * @return array{name: string, phone: string}|null
     */
    private function contactDetails(?User $viewer): ?array
    {
        if ($viewer === null || $this->status !== QuotationStatus::Accepted) {
            return null;
        }

        $job = $this->relationLoaded('serviceRequest') ? $this->serviceRequest : null;

        // The professional's side: the customer's account number.
        if ($viewer->id === $this->professional_id) {
            $customer = $job !== null && $job->relationLoaded('customer')
                ? $job->customer
                : null;

            return $customer?->phone === null || $customer?->phone === ''
                ? null
                : ['name' => $customer->name, 'phone' => $customer->phone];
        }

        // The customer's side: the business number, falling back to the
        // account's number when the professional left the business field blank.
        if ($job !== null && $viewer->id === $job->customer_id) {
            $professional = $this->relationLoaded('professional') ? $this->professional : null;
            $profile = $professional !== null && $professional->relationLoaded('professionalProfile')
                ? $professional->professionalProfile
                : null;

            $phone = $profile?->phone ?: $professional?->phone;

            if ($phone === null || $phone === '') {
                return null;
            }

            return [
                'name' => $profile?->business_name ?? $professional->name,
                'phone' => $phone,
            ];
        }

        return null;
    }
}
