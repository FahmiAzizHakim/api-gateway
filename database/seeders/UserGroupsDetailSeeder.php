<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserGroupsDetailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Grant every group full access to the menu tree. Only the missing
     * (group, menu) pairs are inserted, so a re-seed tops up access to newly
     * added menus without duplicating the grants a group already has.
     *
     * There is one tree for the installation rather than one per website, so
     * there is nothing to narrow here any more (see
     * drop_website_id_from_menus). This seeds the superadmin groups; a group
     * that is meant to see less is edited through /api/admin/access-groups,
     * and a re-seed would hand it everything -- which is the same caveat this
     * seeder always carried.
     *
     * @return void
     */
    public function run()
    {
        $groups  = DB::table('users_menugroup')->get();
        $menuIds = DB::table('menus')->pluck('id');
        $added   = 0;

        foreach ($groups as $group) {
            $granted = DB::table('users_menugroupdetail')
                ->where('usergroup_id', $group->id)
                ->pluck('menu_id')
                ->all();

            foreach ($menuIds->diff($granted) as $menuId) {
                DB::table('users_menugroupdetail')->insert([
                    'usergroup_id' => $group->id,
                    'menu_id'      => $menuId,
                ]);

                $added++;
            }
        }

        $this->command->info("  menu grants added: $added");
    }
}
