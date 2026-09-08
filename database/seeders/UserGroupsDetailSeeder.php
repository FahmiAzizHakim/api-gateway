<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserGroupsDetailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Grant each group full access to the menus of its own website. Only the
     * missing (group, menu) pairs are inserted, so a re-seed tops up access to
     * newly added menus -- for instance a whole new website's tree -- without
     * duplicating the grants a group already has.
     *
     * @return void
     */
    public function run()
    {
        $groups = DB::table('users_menugroup')->get();
        $added  = 0;

        foreach ($groups as $group) {
            $menuIds = DB::table('menus')
                ->where('website_id', $group->website_id)
                ->pluck('id');

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
