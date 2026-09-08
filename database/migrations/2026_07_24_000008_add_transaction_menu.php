<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddTransactionMenu extends Migration
{
    /**
     * Register the "Transactions" menu under each website's "Masterdata"
     * folder and grant it to every existing access group, idempotently.
     *
     * @return void
     */
    public function up()
    {
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            $exists = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_url', 'master/transaction')
                ->exists();

            if ($exists) {
                continue;
            }

            // Attach under the website's own "Masterdata" folder when present.
            $parentId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Masterdata')
                ->value('id');

            $menuId = DB::table('menus')->insertGetId([
                'website_id' => $websiteId,
                'parent_id'  => $parentId,
                'name_in'    => 'Transaksi',
                'name_en'    => 'Transactions',
                'menu_url'   => 'master/transaction',
                'menu_icon'  => 'fas fa-receipt',
                'menu_type'  => 'MENU',
            ]);

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
     * Reverse: remove grants and menu rows.
     *
     * @return void
     */
    public function down()
    {
        $menuIds = DB::table('menus')
            ->where('menu_url', 'master/transaction')
            ->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }
}
