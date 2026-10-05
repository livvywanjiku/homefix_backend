<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfessionalProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'about' => ['nullable', 'string', 'max:2000'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],

            // Kept separate from the account phone: this is the number shown on
            // the profile, which a tradesperson may want to be an office line.
            'phone' => ['nullable', 'string', 'max:20'],

            'base_location_id' => [
                'nullable',
                'integer',
                // Only an active location: a retired town must not be selectable
                // even by a direct request.
                Rule::exists('locations', 'id')->where('is_active', true),
            ],

            // The service areas are sent as a whole set and synced, rather than
            // as individual add/remove calls, so the grid cannot drift out of
            // step with what the professional sees in the editor.
            'service_area_ids' => ['nullable', 'array', 'max:30'],
            'service_area_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_area_ids.*.distinct' => 'Each service area can only be listed once.',
            'service_area_ids.*.exists' => 'One of the selected service areas is not available.',
            'base_location_id.exists' => 'The selected base location is not available.',
        ];
    }
}
