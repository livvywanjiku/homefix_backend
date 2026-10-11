<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Editing another administrator's account (spec §20).
 *
 * The password is optional and only changed when supplied, so a name correction
 * does not silently reset the account's credentials.
 */
class UpdateAdministratorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $administrator = $this->route('administrator');
        $id = is_object($administrator) ? $administrator->getKey() : $administrator;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($id)],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
            'role' => ['sometimes', Rule::in(UserRole::administrativeValues())],
        ];
    }
}
