<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MoveTransactionMenuToMasterdata extends Migration
{
    /**
     * Reparent the "Transactions" menu under each website's "Masterdata"
     * folder (it was originally created top-level). Idempotent.
     *
     * @return void
     */
    public function up()
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

    /**
     * Reverse: detach back to top-level.
     *
     * @return void
     */
    public function down()
    {
        DB::table('menus')
            ->where('menu_url', 'master/transaction')
            ->update(['parent_id' => null]);
    }
}
