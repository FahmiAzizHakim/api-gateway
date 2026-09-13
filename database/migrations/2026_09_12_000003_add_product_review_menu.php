<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register the "Product Reviews" menu under Commerce > Masterdata.
     *
     * Beside Products, because that is what an admin is looking for when they
     * come here -- the reviews of the things they sell. Not under Transaction,
     * even though every review comes from an order: the order is how a review
     * is proved rather than what it is about, and a seller hunting for a
     * complaint about a product would not think to look under Selling.
     *
     * Unlike Campaign and Product Highlight, this one sits in the folder its
     * service belongs to, so nothing clever is going on: `service` is shop
     * because shop-service holds the reviews, and the row goes with the rest
     * of Masterdata when shop-service is down.
     *
     * The screen behind it is moderation only -- list, hide, remove. There is
     * no create and no edit on the API either, because a shop that could write
     * or reword its own reviews would not be publishing reviews, and the
     * "verified purchase" the storefront prints beside each one would stop
     * being true. See shop-service's ProductReviewController.
     *
     * One row, not one per website (see drop_website_id_from_menus), granted
     * to whoever already holds the Masterdata folder it sits in --
     * childrenForRole() gates each level, so a group without the folder could
     * not reach the screen anyway.
     *
     * menu_url IS the frontend route path, so this and the admin frontend have
     * to agree on `commerce/masterdata/review`.
     *
     * The top-up for a database that already has its tree; MenuSeeder declares
     * the same row for a fresh install. Hence the guard.
     */
    public function up(): void
    {
        if (DB::table('menus')->where('menu_url', self::URL)->exists()) {
            return;
        }

        $parentId = DB::table('menus')
            ->where('menu_type', 'FOLDER')
            ->where('name_en', 'Masterdata')
            ->value('id');

        // Fresh database: MenuSeeder builds the whole tree, this row included.
        if (!$parentId) {
            return;
        }

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $parentId,
            'name_in'   => 'Ulasan Produk',
            'name_en'   => 'Product Reviews',
            'menu_url'  => self::URL,
            'menu_icon' => 'fas fa-comment-dots',
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

    protected const URL = 'commerce/masterdata/review';
};
