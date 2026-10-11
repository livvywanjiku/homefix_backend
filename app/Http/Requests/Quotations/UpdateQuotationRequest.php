<?php

namespace App\Http\Requests\Quotations;

use Illuminate\Support\Arr;

/**
 * Revising a live offer (spec §9).
 *
 * Identical to submitting one, minus the job: a quotation cannot be moved to a
 * different request. Allowing that would let a professional re-point a price the
 * customer was already reading, and the one-offer-per-professional-per-job index
 * would be checking a pair that no longer describes the offer.
 */
class UpdateQuotationRequest extends StoreQuotationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['service_request_id']);
    }
}
