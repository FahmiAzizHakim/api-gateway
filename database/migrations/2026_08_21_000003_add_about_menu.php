<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddAboutMenu extends Migration
{
    /**
     * Register the "About" menu under each website's "Website" folder -- next
     * to Banner and Content, since it is website content -- and grant it to
     * every existing access group, idempotently.
     *
     * @return void
     */
    public function up()
    {
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            $menuId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_url', 'website/about')
                ->value('id');

            if (!$menuId) {
                $parentId = DB::table('menus')
                    ->where('website_id', $websiteId)
                    ->where('menu_type', 'FOLDER')
                    ->where('name_en', 'Website')
                    ->value('id');

                $menuId = DB::table('menus')->insertGetId([
                    'website_id' => $websiteId,
                    'parent_id'  => $parentId,
                    'name_in'    => 'Tentang Kami',
                    'name_en'    => 'About',
                    'menu_url'   => 'website/about',
                    'menu_icon'  => 'fas fa-address-card',
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
        $menuIds = DB::table('menus')->where('menu_url', 'website/about')->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }
}
