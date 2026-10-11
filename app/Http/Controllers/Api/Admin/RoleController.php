<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\Setting;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Roles and their permissions (spec §20).
 *
 * The permission *vocabulary* is the `Permission` enum — the same one the route
 * middleware names — so this screen can grant and revoke, but cannot invent a
 * capability the code does not check for. That constraint is the point: a
 * permission with no gate behind it is a checkbox that does nothing.
 *
 * Two roles are protected. `superadmin` holds everything by definition and is
 * not editable. The four permissions that govern administrators, roles,
 * permissions and settings can never be granted to another role, because an
 * administrator who could grant permissions could grant themselves the rest.
 */
class RoleController extends Controller
{
    /**
     * The setting that records which roles have been edited by hand.
     *
     * The seeder reads it and leaves those roles alone. Without it, running
     * `db:seed` against a live database would silently reset every grant an
     * administrator had configured.
     */
    public const CUSTOMISED_SETTING = 'rbac.customised_roles';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The role matrix.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            // One query for the grants, one for the counts, rather than a pair
            // per role.
            ->with('permissions:id,name')
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        $userCounts = $this->userCountsByRole();
        $customised = $this->customisedRoles();

        return response()->json([
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => UserRole::tryFrom($role->name)?->label() ?? ucfirst($role->name),
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'users_count' => $userCounts[$role->id] ?? 0,
                'permissions_count' => $role->permissions_count,
                // The super administrator is the definition of "everything",
                // so editing it could only ever take something away.
                'is_editable' => $role->name !== UserRole::SuperAdmin->value,
                'is_customised' => in_array($role->name, $customised, true),
            ])->values(),
            'permissions' => array_map(
                fn (Permission $permission): array => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                    'reserved' => in_array($permission, UserRole::superAdminOnly(), true),
                ],
                Permission::cases(),
            ),
        ]);
    }

    /**
     * Replace a role's permission set.
     */
    public function update(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        if ($role->name === UserRole::SuperAdmin->value) {
            throw ValidationException::withMessages([
                'permissions' => 'The super administrator holds every permission and cannot be edited.',
            ]);
        }

        $requested = $request->permissions();

        /*
         * Refused rather than silently dropped. A screen that accepted the
         * request and then quietly granted less would leave an administrator
         * believing they had delegated access they had not.
         *
         * Compared by identity rather than with `array_intersect`, which casts
         * each element to a string to do the comparison — and a `Permission` is
         * an enum, so that cast is an error, not a comparison.
         */
        $reserved = array_filter(
            $requested,
            fn (Permission $permission): bool => in_array($permission, UserRole::superAdminOnly(), true),
        );

        if ($reserved !== []) {
            throw ValidationException::withMessages([
                'permissions' => sprintf(
                    '%s %s reserved to the super administrator.',
                    implode(', ', array_map(fn (Permission $p): string => $p->label(), $reserved)),
                    count($reserved) === 1 ? 'is' : 'are',
                ),
            ]);
        }

        $before = $role->permissions()->pluck('name')->values()->all();

        DB::transaction(function () use ($role, $requested): void {
            $role->syncPermissions(Permission::valuesOf($requested));
            $this->markCustomised($role->name);
        });

        $this->audit->recordChange(
            AdminAction::PermissionsUpdated,
            null,
            ['role' => $role->name, 'permissions' => $before],
            ['role' => $role->name, 'permissions' => Permission::valuesOf($requested)],
            "Updated the permissions of the {$role->name} role",
        );

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->refresh()->permissions->pluck('name')->values()->all(),
                'is_customised' => true,
            ],
        ]);
    }

    /**
     * How many accounts hold each role, keyed by role id.
     *
     * Counted from the pivot rather than through Spatie's `users()` relation.
     * That relation resolves the related model from the *default* guard, and
     * Laravel sets the default to `sanctum` for the duration of any API
     * request. Sanctum registers its guard without a provider, so the lookup
     * returned null and `withCount('users')` failed with "Class name must be a
     * valid object or a string" — while the identical query worked in tinker,
     * where the default guard is still `web`.
     *
     * Reading the pivot directly sidesteps the guard entirely, and it is the
     * same number: roles are held by accounts, and there is one account model.
     *
     * @return array<int, int>
     */
    private function userCountsByRole(): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->map(fn (int|string $total): int => (int) $total)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function customisedRoles(): array
    {
        $stored = Setting::get(self::CUSTOMISED_SETTING, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Records that this role's grants are now an administrator's decision, so
     * the seeder stops treating them as defaults.
     */
    private function markCustomised(string $role): void
    {
        $customised = $this->customisedRoles();

        if (in_array($role, $customised, true)) {
            return;
        }

        $customised[] = $role;

        Setting::updateOrCreate(
            ['key' => self::CUSTOMISED_SETTING],
            [
                'value' => json_encode(array_values($customised)),
                'type' => 'json',
                'group' => 'system',
                'is_public' => false,
                'label' => 'Roles edited by hand',
                'description' => 'Roles whose permissions were changed in the console. The seeder leaves these alone.',
            ],
        );
    }
}
