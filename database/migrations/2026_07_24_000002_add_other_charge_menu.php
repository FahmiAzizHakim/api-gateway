<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddOtherChargeMenu extends Migration
{
    /**
     * Register the "Other Charges" master-data menu for every website and
     * grant it to every existing access group, so the sidebar link and the
     * menu-access checks work without a full re-seed.
     *
     * @return void
     */
    public function up()
    {
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            // Attach under the website's own "Masterdata" folder when present.
            $parentId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Masterdata')
                ->value('id');

            // Skip if it already exists (idempotent).
            $exists = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_url', 'master/othercharge')
                ->exists();

            if ($exists) {
                continue;
            }

            $menuId = DB::table('menus')->insertGetId([
                'website_id' => $websiteId,
                'parent_id'  => $parentId,
                'name_in'    => 'Biaya Lainnya',
                'name_en'    => 'Other Charges',
                'menu_url'   => 'master/othercharge',
                'menu_icon'  => 'fas fa-coins',
                'menu_type'  => 'MENU',
            ]);

            // Grant to every access group of this website.
            $groupIds = DB::table('users_menugroup')
                ->where('website_id', $websiteId)
                ->pluck('id');

            foreach ($groupIds as $groupId) {
                DB::table('users_menugroupdetail')->insert([
                    'usergroup_id' => $groupId,
                    'menu_id'      => $menuId,
                ]);
            }
        }
    }

    /**
     * Reverse the migration: remove the grants and the menu rows.
     *
     * @return void
     */
    public function down()
    {
        $menuIds = DB::table('menus')
            ->where('menu_url', 'master/othercharge')
            ->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }
}
