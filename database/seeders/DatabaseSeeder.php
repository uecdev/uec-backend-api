<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe seeders only. No sample content is seeded: real hymns,
     * devotionals, lessons, events and branches are entered by UEC's content team
     * through the admin dashboard (or imported, e.g. `php artisan uec:bible:import`).
     * Create the first administrator with `php artisan uec:admin:create`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
        ]);
    }
}
