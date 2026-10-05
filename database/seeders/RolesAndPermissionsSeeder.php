<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
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

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value)->syncPermissions($role->permissionValues());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
