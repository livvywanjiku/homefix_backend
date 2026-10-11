<?php

namespace App\Http\Requests\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filter set behind professional search (spec §13).
 *
 * Every one of these is applied in SQL by the controller. Nothing here is a
 * hint to the client — a filter the customer sets has to change what the
 * database returns, not what the browser hides.
 */
class ListProfessionalSearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],

            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],

            /*
             * Both a radius and a distance sort need somewhere to measure from,
             * so the town is required whenever either is asked for. Left
             * optional, `max_distance_km` alone would have no origin and the
             * controller would have to guess one.
             */
            'location_id' => [
                'nullable',
                'integer',
                // Only a town still offered to customers can anchor a search;
                // a retired one is a bookmarked link, not a valid origin.
                Rule::exists('locations', 'id')->where('is_active', true),
                'required_with:max_distance_km',
                'required_if:sort,distance',
            ],
            'max_distance_km' => ['nullable', 'integer', 'min:1', 'max:500'],

            /*
             * Compared against a professional's *starting* price, so the
             * customer is asking "who could do this from under X". Quote-only
             * offerings have no starting price and therefore cannot be shown to
             * satisfy a bracket; see the controller.
             */
            'min_price_cents' => ['nullable', 'integer', 'min:0'],
            'max_price_cents' => ['nullable', 'integer', 'min:0', 'gte:min_price_cents'],

            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],

            // A day the customer wants somebody to turn up on. Filtered against
            // the weekly pattern and the blocked dates, not against the request.
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],

            'sort' => ['nullable', Rule::in([
                'rating', 'reviews', 'experience', 'price_low', 'price_high', 'newest', 'distance',
            ])],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required_with' => 'Choose a location to search within a distance of it.',
            'location_id.required_if' => 'Choose a location to sort by distance.',
        ];
    }
}
