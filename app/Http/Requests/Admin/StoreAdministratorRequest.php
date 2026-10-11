<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creating an administrator (spec §20).
 *
 * The role is restricted to the two administrative roles by the enum, so the
 * endpoint cannot be used to mint a customer or a professional — that is the
 * registration flow's job, and mixing the two would make "administrator" a
 * meaningless label.
 */
class StoreAdministratorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(UserRole::administrativeValues())],
        ];
    }
}
