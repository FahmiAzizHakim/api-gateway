<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * One admin per website, including the new EV Charging site. admin_website_id()
     * reads users.website_id, so this is what scopes an admin to their own
     * catalog.
     *
     * Existing accounts are left completely alone -- only missing emails are
     * created -- so re-seeding never resets a password that has been changed.
     *
     * @return void
     */
    public function run()
    {
        $users = [
            ['name' => 'Admin Cargo',       'email' => 'admin@cargo.com',       'roles_code' => 'superadmin-cargo',     'website_id' => 1],
            ['name' => 'Admin Instalasi',   'email' => 'admin@instalasi.com',   'roles_code' => 'superadmin-instalasi', 'website_id' => 2],
            ['name' => 'Admin EV Charging', 'email' => 'admin@evcharging.com',  'roles_code' => 'superadmin-ev',        'website_id' => 3],
        ];

        foreach ($users as $user) {
            if (DB::table('users')->where('email', $user['email'])->exists()) {
                $this->command->line("  {$user['email']} already exists, left as is");
                continue;
            }

            DB::table('users')->insert($user + ['password' => Hash::make('password123')]);
            $this->command->info("  created {$user['email']} (website {$user['website_id']})");
        }
    }
}
