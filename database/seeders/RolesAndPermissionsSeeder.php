<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the role and permission catalogue.
 *
 * Idempotent: safe to re-run against an existing database. The grant table is
 * read from {@see UserRole::permissions()} so this seeder and the application
 * can never disagree about what a role may do.
 *
 * The enum describes the *default* grants, not the current ones. Once an
 * administrator has edited a role in the console, that role is recorded as
 * customised and left alone here — re-running the seeder against a live database
 * must not quietly undo decisions somebody made in the UI.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $name) {
            Permission::findOrCreate($name);
        }

        // spatie resolves permission names through a cached collection that was
        // populated (empty) before the loop above. Without this second flush,
        // syncPermissions() looks up names that the cache does not yet know.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customised = $this->customisedRoles();

        foreach (UserRole::cases() as $role) {
            $model = Role::findOrCreate($role->value);

            if (in_array($role->value, $customised, true)) {
                $this->command?->warn(
                    "Leaving the {$role->value} role as configured in the console.",
                );

                continue;
            }

            $model->syncPermissions($role->permissionValues());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Roles an administrator has edited, which this seeder must not overwrite.
     *
     * Read defensively: on a first run the settings table may not exist yet, and
     * a seeding step that threw over a missing table would make `migrate:fresh
     * --seed` fail on the very first boot.
     *
     * @return array<int, string>
     */
    private function customisedRoles(): array
    {
        try {
            $stored = Setting::get(RoleController::CUSTOMISED_SETTING, []);
        } catch (\Throwable) {
            return [];
        }

        return is_array($stored) ? $stored : [];
    }
}
