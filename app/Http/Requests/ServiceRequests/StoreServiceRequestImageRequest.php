<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequestImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Choose at least one photo.',
            'images.max' => 'Add at most 5 photos at a time.',
            'images.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'images.*.max' => 'Each photo must be 4 MB or smaller.',
        ];
    }
}
