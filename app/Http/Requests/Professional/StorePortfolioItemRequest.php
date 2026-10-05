<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioItemRequest extends FormRequest
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

            // A portfolio shows finished work, so a future completion date is
            // a mistake rather than a plan.
            'completed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['boolean'],

            /*
             * Photos are validated by real content, not by the extension or the
             * client-supplied MIME type: `image` inspects the file, and the
             * mime whitelist excludes SVG, which can carry script.
             */
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.*.mimes' => 'Photos must be a JPG, PNG or WebP image.',
            'images.*.max' => 'Each photo must be 4 MB or smaller.',
            'images.*.image' => 'That file is not an image.',
            'completed_on.before_or_equal' => 'The completion date cannot be in the future.',
        ];
    }
}
