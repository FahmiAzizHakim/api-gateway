<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register the "Campaign" menu under the Website folder.
     *
     * The promotional slider -- an event, a sale, a launch -- so it sits next
     * to Banner, which is the other screen that manages images on the landing
     * page. They are different rows for the reason they are different tables:
     * a banner is the site's own hero and a campaign is temporary and links
     * away from the page.
     *
     * One row, not one per website: drop_website_id_from_menus collapsed the
     * three trees into one that every site shares, so there is no loop over
     * config('sites.ids') here as there was in every menu migration before it.
     * What scopes an admin to their own campaigns is the grant below plus the
     * website_id on the token, not the menu row.
     *
     * Granted to the groups that already hold the Website folder rather than
     * to every group. MenuRepository::childrenForRole() gates each level, so a
     * group without the folder could not reach this screen anyway -- and a
     * group deliberately kept out of the site's content has no business being
     * handed a new content screen by a migration.
     *
     * menu_url IS the frontend route path -- the sidebar navigates to whatever
     * this column says -- so this and the admin frontend's website routes have
     * to agree on `website/campaign`.
     *
     * 'service' is website: the screen is forwarded to website-service, which
     * holds the table, so the sidebar leaves the row out while that service is
     * not answering rather than opening a page of 502s.
     *
     * This is the top-up for a database that already has its tree. The row is
     * also declared in MenuSeeder, which is what a fresh install builds it
     * from: migrations run before any seeder, so on an empty database the
     * Website folder does not exist yet and there would be no parent to attach
     * to. Hence the guard -- with no folder to hang it on, this does nothing
     * and leaves the row to the seeder.
     */
    public function up(): void
    {
        if (DB::table('menus')->where('menu_url', self::URL)->exists()) {
            return;
        }

        $parentId = DB::table('menus')
            ->where('menu_type', 'FOLDER')
            ->where('name_en', 'Website')
            ->value('id');

        // Fresh database: MenuSeeder builds the whole tree, this row included,
        // and will place it correctly.
        if (!$parentId) {
            return;
        }

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $parentId,
            'name_in'   => 'Kampanye',
            'name_en'   => 'Campaign',
            'menu_url'  => self::URL,
            'menu_icon' => 'fas fa-bullhorn',
            'menu_type' => 'MENU',
            'service'   => 'website',
        ]);

        $groupIds = DB::table('users_menugroupdetail')
            ->where('menu_id', $parentId)
            ->pluck('usergroup_id');

        foreach ($groupIds as $groupId) {
            DB::table('users_menugroupdetail')->insert([
                'usergroup_id' => $groupId,
                'menu_id'      => $menuId,
            ]);
        }
    }

    /** The grants first, or the foreign key bites. */
    public function down(): void
    {
        $menuIds = DB::table('menus')->where('menu_url', self::URL)->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
    }

    protected const URL = 'website/campaign';
};
