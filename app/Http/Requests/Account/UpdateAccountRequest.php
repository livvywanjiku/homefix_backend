<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields an account may change about itself.
 *
 * Email is deliberately absent: changing it needs a verification step the
 * product does not have yet, and silently accepting a new address would make
 * sign-in and password recovery unpredictable. The phone is required because it
 * is the number revealed to a professional after an award — a profile save is
 * the natural moment to make sure one exists.
 */
class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
        ];
    }
}
