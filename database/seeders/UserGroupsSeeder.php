<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserGroupsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * One super-admin group per website, including the new EV Charging site.
     * Keyed on (website_id, code) and updated in place, so re-seeding does not
     * create a second group -- users.roles_code and users_menugroupdetail both
     * point at these rows.
     *
     * @return void
     */
    public function run()
    {
        $groups = [
            ['website_id' => 1, 'code' => 'superadmin-cargo',      'name' => 'Super Admin Cargo'],
            ['website_id' => 2, 'code' => 'superadmin-instalasi',  'name' => 'Super Admin Instalasi'],
            ['website_id' => 3, 'code' => 'superadmin-ev',         'name' => 'Super Admin EV Charging'],
        ];

        foreach ($groups as $group) {
            DB::table('users_menugroup')->updateOrInsert(
                ['website_id' => $group['website_id'], 'code' => $group['code']],
                ['name' => $group['name'], 'activestatus' => '1']
            );
        }

        $this->command->info('  groups: ' . implode(', ', array_column($groups, 'code')));
    }
}
