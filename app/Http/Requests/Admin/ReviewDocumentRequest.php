<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator's decision on a single uploaded document.
 */
class ReviewDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required_if' => 'Say why the document was rejected so it can be replaced.',
        ];
    }
}
