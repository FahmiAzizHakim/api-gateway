<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register the "QRIS" menu under each website's "Commerce" folder and
     * grant it to every existing access group, idempotently.
     *
     * Next to Banks, because it is the same kind of row to an admin: somewhere
     * a customer's money arrives. That it is answered by the gateway rather
     * than shop-service is not something the sidebar has any reason to show.
     *
     * menu_url IS the frontend route path -- the sidebar navigates to whatever
     * this column says -- so this and vue-vite's commerce/routes.ts have to
     * agree on `commerce/qris`.
     *
     * This is the top-up for a database that already has its tree. The row is
     * also declared in MenuSeeder, which is what a fresh install gets it from:
     * migrations run before any seeder, so on an empty database the Commerce
     * folder does not exist yet and there would be no parent to attach to --
     * which is how About and Landing Sections once ended up at the top level.
     * Hence the guard below: with no folder to hang it on, this does nothing
     * and leaves the row to the seeder.
     */
    public function up(): void
    {
        foreach (config('sites.ids') as $websiteId) {
            if (DB::table('menus')->where('website_id', $websiteId)->where('menu_url', 'commerce/qris')->exists()) {
                continue;
            }

            $parentId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Commerce')
                ->value('id');

            // Fresh database: MenuSeeder builds the whole tree, this one
            // included, and will place it correctly.
            if (!$parentId) {
                continue;
            }

            $menuId = DB::table('menus')->insertGetId([
                'website_id' => $websiteId,
                'parent_id'  => $parentId,
                'name_in'    => 'QRIS',
                'name_en'    => 'QRIS',
                'menu_url'   => 'commerce/qris',
                'menu_icon'  => 'fas fa-qrcode',
                'menu_type'  => 'MENU',
            ]);

            $groupIds = DB::table('users_menugroup')->where('website_id', $websiteId)->pluck('id');

            foreach ($groupIds as $groupId) {
                DB::table('users_menugroupdetail')->insert([
                    'usergroup_id' => $groupId,
                    'menu_id'      => $menuId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')->where('menu_url', 'commerce/qris')->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }
};
