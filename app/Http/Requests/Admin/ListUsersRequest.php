<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters the administration's user list accepts (spec §20).
 *
 * Everything here narrows a query that has already been authorised by route
 * middleware and a policy. None of it can widen one: `role` picks which slice of
 * the same authorised list to show, it does not select what the caller may see.
 */
class ListUsersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],

            // Matches name, email or phone — the three things an administrator
            // has when a customer calls about their account.
            'q' => ['nullable', 'string', 'max:100'],

            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'name', 'active'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
