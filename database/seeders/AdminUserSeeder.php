<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the platform administrator.
 *
 * Registration can only ever produce a customer or a professional, so this
 * seeder is the sole way an admin account comes into existence. Credentials
 * come from ADMIN_EMAIL / ADMIN_PASSWORD; there is no hardcoded fallback
 * password, because a silently-weak default is worse than no admin at all.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('homefix.admin.email');
        $password = (string) config('homefix.admin.password');

        if ($password === '') {
            $this->command->warn(
                'Skipping admin user: set ADMIN_PASSWORD in .env to create one.'
            );

            return;
        }

        // firstOrCreate rather than updateOrCreate: re-seeding must not reset a
        // password the administrator has since changed.
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Administrator',
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(UserRole::Admin->value);

        $this->command->info("Admin user ready: {$email}");
    }
}
