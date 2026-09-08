<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class RenameMasterdataMenuToCommerce extends Migration
{
    /**
     * Rename each website's "Masterdata" folder to "Commerce" / "Perdagangan".
     *
     * The folder holds what the shop sells -- services, categories, products,
     * packages, delivery prices, other charges, banks -- which is commerce
     * rather than reference data, and "Master data" said nothing to the people
     * using it. Only the two label columns change: the children keep their
     * parent_id, and every menu_url stays as it is, so no route moves and no
     * access grant is touched.
     *
     * Matched on name_en rather than id because the ids differ per website
     * (see MenuSeeder, which creates one tree per site). Idempotent: a second
     * run finds nothing left called Masterdata.
     *
     * This has to run before MenuSeeder is re-run, because the seeder matches
     * existing rows on name_en -- against the old name it would insert a
     * second folder rather than update this one.
     *
     * @return void
     */
    public function up()
    {
        DB::table('menus')
            ->where('menu_type', 'FOLDER')
            ->where('name_en', 'Masterdata')
            ->update([
                'name_en' => 'Commerce',
                'name_in' => 'Perdagangan',
            ]);
    }

    /**
     * Reverse: back to the original labels.
     *
     * Note that the earlier menu migrations look their parent folder up by
     * name_en = 'Masterdata'; they have all run already, so the rename does
     * not disturb them, but rolling this back is what makes their own down()
     * paths resolve again.
     *
     * @return void
     */
    public function down()
    {
        DB::table('menus')
            ->where('menu_type', 'FOLDER')
            ->where('name_en', 'Commerce')
            ->update([
                'name_en' => 'Masterdata',
                'name_in' => 'Master data',
            ]);
    }
}
