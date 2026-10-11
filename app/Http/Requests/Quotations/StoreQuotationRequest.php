<?php

namespace App\Http\Requests\Quotations;

use App\Enums\QuotationItemCategory;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Submitting a price offer on an open job (spec §9).
 *
 * The request carries prices, which is worth being explicit about: a
 * professional naming their own labour rate is the whole point of quoting, and
 * is a different act from a client telling the platform what something costs.
 * The distinction is enforced downstream — `unit_price_cents` is accepted
 * because the professional is offering it, while every derived figure (the line
 * totals, the four category totals, the grand total) is computed server-side in
 * `QuotationItem` and `Quotation` and can never be supplied here.
 *
 * There is no `total_cents` field to submit, deliberately. A client that sends
 * one is not rejected; the field simply does not exist, so it is ignored.
 */
class StoreQuotationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The job being quoted on. It is validated for existence rather than
             * against `accepts_quotations` here — the policy decides whether this
             * professional may quote on this request, and duplicating the rule
             * would give two places to keep in step.
             */
            'service_request_id' => ['required', 'integer', 'exists:service_requests,id'],

            // The covering note: what the price includes, and what it assumes.
            'message' => ['nullable', 'string', 'max:2000'],

            // Working days. A ceiling of a year because anything longer is not a
            // schedule, it is a refusal.
            'estimated_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // A start date in the past is a typo, not an offer.
            'available_from' => ['nullable', 'date', 'after_or_equal:today'],

            /*
             * How long the price stands. Strictly after today: a quotation that
             * expires on submission cannot be accepted, so accepting one would
             * always fail — better to refuse the offer than to store one nobody
             * can take.
             */
            'valid_until' => ['nullable', 'date', 'after:today'],

            /*
             * At least one line, and no more than the model's ceiling. A
             * quotation with no lines has no price, and the four category totals
             * would all be zero — which reads as "free", not as "unpriced".
             */
            'items' => ['required', 'array', 'min:1', 'max:'.Quotation::MAX_ITEMS],
            'items.*.category' => ['required', Rule::enum(QuotationItemCategory::class)],
            'items.*.description' => ['required', 'string', 'max:150'],

            // Two decimal places, and a minimum of one hundredth — a zero-quantity
            // line is a line that should not have been sent.
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],

            // At least one cent: a zero-price line is either a mistake or an
            // attempt to make a total that does not reflect the work.
            'items.*.unit_price_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_request_id.exists' => 'That job is no longer available.',
            'items.required' => 'Break the price down into at least one line.',
            'items.min' => 'Break the price down into at least one line.',
            'items.max' => 'A quotation can hold at most '.Quotation::MAX_ITEMS.' lines.',
            'items.*.description.required' => 'Describe what each line covers.',
            'items.*.quantity.min' => 'A quantity must be greater than zero.',
            'items.*.unit_price_cents.min' => 'A unit price must be greater than zero.',
            'valid_until.after' => 'A price must stand for at least a day.',
            'available_from.after_or_equal' => 'Choose a start date today or later.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'service_request_id' => 'job',
            'items.*.unit_price_cents' => 'unit price',
        ];
    }
}
