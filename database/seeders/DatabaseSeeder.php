<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the API gateway.
     *
     * Two halves:
     *
     *  - Reference data (codes, regions, couriers). Slow, and only a fresh
     *    install needs it -- these seeders insert unconditionally.
     *  - SiteStructureSeeder: admin access. Safe to re-run on a live database:
     *        php artisan db:seed --class=SiteStructureSeeder
     */
    public function run(): void
    {
        // ---- Reference data (fresh install only) ----
        $this->call(CodesSeeder::class);
        $this->call(RajaongkirmapTableSeeder::class);
        $this->call(GlbCountriesTableSeeder::class);
        $this->call(GlbProvincesTableSeeder::class);
        $this->call(GlbCitiesTableSeeder::class);
        $this->call(GlbDistrictsTableSeeder::class);
        $this->call(CouriersTableSeeder::class);

        // ---- Accounts, access groups and the admin menu tree ----
        $this->call(SiteStructureSeeder::class);
    }
}
