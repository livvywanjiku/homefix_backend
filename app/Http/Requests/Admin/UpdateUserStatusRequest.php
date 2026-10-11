<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suspending or reinstating an account.
 *
 * Written as an explicit `action` rather than a `status` field so that the set
 * of transitions an administrator can make is closed and readable. A request
 * cannot ask for an account to be left in a state the platform does not have.
 */
class UpdateUserStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['suspend', 'activate'])],

            // Required to suspend, and only then: reinstating an account needs
            // no justification, but taking one away without a reason leaves
            // support with nothing to tell the person who calls.
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,suspend'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Record why the account is being suspended.',
        ];
    }
}
