<?php

namespace App\Http\Requests\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

class ListServicesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A slug, not an id, so the filter is readable and stable in a URL.
            'category' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
