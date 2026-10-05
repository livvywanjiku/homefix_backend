<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Http\Requests\Auth\Concerns\DeterminesDeviceName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use DeterminesDeviceName;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:255'],

            // The admin role is never self-assignable: this list is derived from
            // UserRole::selfAssignable(), so adding a privileged role to that
            // enum without thinking about registration is impossible.
            'role' => ['required', 'string', Rule::in(UserRole::selfAssignableValues())],
        ];
    }
}
