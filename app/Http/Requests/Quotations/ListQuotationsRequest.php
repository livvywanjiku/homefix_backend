<?php

namespace App\Http\Requests\Quotations;

use App\Enums\QuotationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters a professional's own quotation list accepts (spec §9).
 *
 * A `status` filter is offered but cannot be used to escape authorisation: the
 * controller scopes the query to the authenticated account before any filter is
 * applied, so asking for `rejected` returns this professional's own rejected
 * offers rather than everyone's.
 */
class ListQuotationsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(QuotationStatus::class)],
            'service_request_id' => ['nullable', 'integer', 'exists:service_requests,id'],

            'q' => ['nullable', 'string', 'max:100'],

            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'value'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
