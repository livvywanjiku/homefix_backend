<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replacing a role's permission set (spec §20).
 *
 * The list is validated against the enum, so a typo is a 422 rather than a
 * silently ignored grant. What a role is *allowed* to hold is decided by the
 * controller, not here — this rule is about the shape of the request, not about
 * who may end up with what.
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ];
    }

    /**
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return array_map(
            fn (string $value): Permission => Permission::from($value),
            $this->validated('permissions'),
        );
    }
}
