<?php

namespace App\Http\Requests\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestUrgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters the customer's own list accepts (spec §8).
 *
 * A `status` filter is offered but cannot be used to escape authorisation: the
 * controller scopes the query to the authenticated account first, so a customer
 * asking for `cancelled` sees their own cancelled requests rather than everyone's.
 */
class ListServiceRequestsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ServiceRequestStatus::class)],
            'urgency' => ['nullable', Rule::enum(ServiceRequestUrgency::class)],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'q' => ['nullable', 'string', 'max:100'],

            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'quotes', 'expiring'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
