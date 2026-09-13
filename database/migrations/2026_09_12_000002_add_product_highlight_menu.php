<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register the "Product Highlight" menu under the Website folder.
     *
     * Under Website rather than Commerce, and that is a deliberate split: a
     * highlight changes nothing about a product, only which products the page
     * leads with. It is a decision about the site, so it sits with Banner and
     * Campaign -- the other two screens that decide what the landing page
     * shows -- rather than with the catalogue it points at.
     *
     * `service` is shop nonetheless, because that is a different question. The
     * folder says where an admin looks for the screen; this column says which
     * service answers it, and the table, the products and the endpoints behind
     * this one are shop-service's. So the row drops out of the sidebar when
     * shop-service is down, while the rest of Website stays -- which is the
     * honest behaviour: with shop-service unreachable there are no products to
     * pick from and the screen would be a page of 502s.
     *
     * It is the first row in the tree whose folder and service disagree.
     * Nothing needed changing for that: MenuService reads `service` per row
     * and the folder's own service is null, so Website stays put while this
     * one row goes.
     *
     * One row, not one per website -- drop_website_id_from_menus collapsed the
     * three trees into one that every site shares. Granted to the groups that
     * already hold the Website folder, for the reason add_campaign_menu gives:
     * childrenForRole() gates each level, so a group without the folder could
     * not reach the screen anyway.
     *
     * menu_url IS the frontend route path, so this and the admin frontend have
     * to agree on `website/product-highlight`.
     *
     * As with every menu migration here, this is the top-up for a database
     * that already has its tree; MenuSeeder declares the same row and is what
     * a fresh install builds it from. Hence the guard -- with no Website
     * folder to hang it on, this does nothing.
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

        // Fresh database: MenuSeeder builds the whole tree, this row included.
        if (!$parentId) {
            return;
        }

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $parentId,
            'name_in'   => 'Produk Unggulan',
            'name_en'   => 'Product Highlight',
            'menu_url'  => self::URL,
            'menu_icon' => 'fas fa-star',
            'menu_type' => 'MENU',
            'service'   => 'shop',
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

    protected const URL = 'website/product-highlight';
};
