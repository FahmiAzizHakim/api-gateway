<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPackageMenu extends Migration
{
    /**
     * Register the "Packages" master-data menu under each website's "Masterdata"
     * folder and grant it to every existing access group (superadmin included),
     * idempotently.
     *
     * @return void
     */
    public function up()
    {
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            $menuId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_url', 'master/package')
                ->value('id');

            if (!$menuId) {
                $parentId = DB::table('menus')
                    ->where('website_id', $websiteId)
                    ->where('menu_type', 'FOLDER')
                    ->where('name_en', 'Masterdata')
                    ->value('id');

                $menuId = DB::table('menus')->insertGetId([
                    'website_id' => $websiteId,
                    'parent_id'  => $parentId,
                    'name_in'    => 'Paket',
                    'name_en'    => 'Packages',
                    'menu_url'   => 'master/package',
                    'menu_icon'  => 'fas fa-box-open',
                    'menu_type'  => 'MENU',
                ]);
            }

            // Grant to every access group of this website that lacks it.
            $groupIds = DB::table('users_menugroup')->where('website_id', $websiteId)->pluck('id');

            foreach ($groupIds as $groupId) {
                $granted = DB::table('users_menugroupdetail')
                    ->where('usergroup_id', $groupId)
                    ->where('menu_id', $menuId)
                    ->exists();

                if (!$granted) {
                    DB::table('users_menugroupdetail')->insert([
                        'usergroup_id' => $groupId,
                        'menu_id'      => $menuId,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse: remove grants and menu rows.
     *
     * @return void
     */
    public function down()
    {
        $menuIds = DB::table('menus')->where('menu_url', 'master/package')->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }
}
