<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator's decision on a professional's verification application.
 */
class ReviewProfessionalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['verify', 'reject', 'suspend'])],

            // A rejection or a suspension without a reason leaves the
            // professional with nothing to act on, so the note is required
            // exactly when the decision is adverse.
            'notes' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:decision,reject',
                'required_if:decision,suspend',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required_if' => 'Tell the professional why, so they can put it right.',
            'decision.in' => 'Choose whether to verify, reject or suspend.',
        ];
    }
}
