<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MoveTransactionMenuToToplevel extends Migration
{
    /**
     * Move the "Transactions" menu out of the Masterdata folder up to the top
     * level (same level as Dashboard and Inbox). Idempotent.
     *
     * @return void
     */
    public function up()
    {
        DB::table('menus')
            ->where('menu_url', 'master/transaction')
            ->update(['parent_id' => null]);
    }

    /**
     * Reverse: put it back under each website's Masterdata folder.
     *
     * @return void
     */
    public function down()
    {
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            $parentId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Masterdata')
                ->value('id');

            if (!$parentId) {
                continue;
            }

            DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_url', 'master/transaction')
                ->update(['parent_id' => $parentId]);
        }
    }
}
