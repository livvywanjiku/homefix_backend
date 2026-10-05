<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles and permissions must exist before any user is given one.
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);

        // The catalogue is independent of identity, so it seeds separately and
        // can be re-run on a live database without touching accounts.
        $this->call(CatalogueSeeder::class);
    }
}
