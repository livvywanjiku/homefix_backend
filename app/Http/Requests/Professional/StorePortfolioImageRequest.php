<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;

class StorePortfolioImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Choose at least one photo.',
            'images.*.mimes' => 'Photos must be a JPG, PNG or WebP image.',
            'images.*.max' => 'Each photo must be 4 MB or smaller.',
            'images.*.image' => 'That file is not an image.',
        ];
    }
}
