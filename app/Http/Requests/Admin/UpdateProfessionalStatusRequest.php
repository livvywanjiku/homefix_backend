<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suspending or reinstating a professional's listing (spec §20).
 *
 * Distinct from the verification decision: verification asks "may this person
 * trade here at all", a suspension asks "should they stop for now". A suspended
 * professional keeps their profile and their history and is still re-instated by
 * an administrator, which is why the suspension is a status on the profile
 * rather than a deletion.
 */
class UpdateProfessionalStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['suspend', 'reinstate'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,suspend'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Record why the listing is being suspended.',
        ];
    }
}
