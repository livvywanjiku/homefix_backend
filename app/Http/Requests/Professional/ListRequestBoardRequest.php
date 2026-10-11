<?php

namespace App\Http\Requests\Professional;

use App\Enums\ServiceRequestUrgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters the open-request board accepts (spec §8).
 */
class ListRequestBoardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * `matching` is the default view: jobs in the services this
             * professional offers, in the towns they cover. `all` is the whole
             * board, which is useful for seeing what else is out there before
             * widening a service area.
             */
            'scope' => ['nullable', Rule::in(['matching', 'all'])],

            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'urgency' => ['nullable', Rule::enum(ServiceRequestUrgency::class)],

            // Budget filters compare against the customer's range, so a
            // professional can ask for jobs that can afford their rate.
            'min_budget_cents' => ['nullable', 'integer', 'min:0'],

            // Only meaningful against the professional's base location.
            'max_distance_km' => ['nullable', 'integer', 'min:1', 'max:500'],

            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['recent', 'urgent', 'budget', 'expiring'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
