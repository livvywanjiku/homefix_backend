<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a portfolio item.
 *
 * Photos are added and removed through their own endpoints rather than being
 * resent with the text fields, so editing a caption cannot discard an upload
 * that the client did not know about.
 */
class UpdatePortfolioItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'completed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'completed_on.before_or_equal' => 'The completion date cannot be in the future.',
        ];
    }
}
