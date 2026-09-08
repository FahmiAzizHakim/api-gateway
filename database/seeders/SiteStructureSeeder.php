<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SiteStructureSeeder extends Seeder
{
    /**
     * Who may sign in, and what their sidebar shows.
     *
     * One super-admin group and one admin account per website in
     * config('sites.ids'):
     *
     *   1  GLS Kontrak Logistic       admin@cargo.com
     *   2  Furniture/AC Installation  admin@instalasi.com
     *   3  EV Charging Solution       admin@evcharging.com
     *
     * Safe to re-run: every step is keyed and updated in place, so re-seeding
     * never costs an admin their password.
     *
     * The websites themselves live in website-service and their catalog in
     * shop-service; the three databases only have to agree on the ids.
     *
     * Run on its own with:
     *   php artisan db:seed --class=SiteStructureSeeder
     *
     * which skips the slow reference data (codes, regions, couriers) that only
     * a fresh install needs.
     *
     * @return void
     */
    public function run()
    {
        $steps = [
            'Admin menus'   => MenuSeeder::class,
            'Access groups' => UserGroupsSeeder::class,
            'Menu grants'   => UserGroupsDetailSeeder::class,
            'Admin users'   => UsersSeeder::class,
        ];

        foreach ($steps as $label => $class) {
            $this->command->newLine();
            $this->command->comment($label);
            $this->call($class);
        }
    }
}
