<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class RenameMasterMenuUrlsToCommerce extends Migration
{
    /**
     * Move the commerce screens from master/* to commerce/*.
     *
     * The folder became Commerce in the label first; this finishes the job in
     * the URLs, so the sidebar row, the frontend route and the module the
     * screen lives in all read the same way.
     *
     * Only these seven move. master/user and master/groupmenu belong to
     * Settings, and master/transaction sits at the top level rather than in
     * this folder, so none of them is commerce and none is touched.
     *
     * A menu_url is a frontend route path: vue-vite reads it off the row and
     * navigates to it, so this migration and vue-vite's commerce/routes.ts
     * have to move together. Nothing else keys on the value -- the access
     * grants in users_menugroupdetail point at menu ids, which do not change
     * here -- so no permission is lost.
     *
     * Keyed on the old value rather than on an id, because the ids differ per
     * website (one tree each, see MenuSeeder). Idempotent: a second run finds
     * no master/* rows left among these seven.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::MAP as $old => $new) {
            DB::table('menus')->where('menu_url', $old)->update(['menu_url' => $new]);
        }
    }

    /**
     * Reverse: back to master/*.
     *
     * The earlier menu migrations look their rows up by the old menu_url
     * (add_bank_menu, add_package_menu, add_other_charge_menu). They have all
     * run already, so this rename does not disturb them -- but rolling this
     * back is what makes their own down() paths resolve again.
     *
     * @return void
     */
    public function down()
    {
        foreach (self::MAP as $old => $new) {
            DB::table('menus')->where('menu_url', $new)->update(['menu_url' => $old]);
        }
    }

    /**
     * Old path => new path. The screens the Commerce folder holds.
     */
    protected const MAP = [
        'master/service'       => 'commerce/service',
        'master/categories'    => 'commerce/categories',
        'master/product'       => 'commerce/product',
        'master/package'       => 'commerce/package',
        'master/deliveryprice' => 'commerce/deliveryprice',
        'master/othercharge'   => 'commerce/othercharge',
        'master/bank'          => 'commerce/bank',
    ];
}
