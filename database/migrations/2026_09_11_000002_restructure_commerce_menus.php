<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gather everything commercial under Commerce, in three folders.
     *
     * Commerce had grown into a flat list of eight screens with a separate
     * top-level Transaction folder beside it, which put reference data, prices
     * and orders all at the same depth. Now:
     *
     *   Commerce/
     *     Masterdata/   Services, Categories, Products, Packages, Banks, QRIS
     *     Price/        Delivery Prices, Other Charges
     *     Transaction/  Selling, Balance, Statistics   (moved in, whole)
     *
     * Rows are moved, never replaced. An id is what the grants in
     * users_menugroupdetail point at, so a fresh row plus a deleted one would
     * strand every grant that named the old menu; re-parenting keeps the id,
     * the grants and the history, and the sidebar finds the row one level
     * deeper. This is the same reason move_transaction_menu_into_folder
     * renamed its row in place rather than inserting one.
     *
     * The two new folders need grants of their own, because
     * MenuRepository::childrenForRole() gates every level: a child whose
     * parent the group cannot see is a child nobody reaches. They are granted
     * to whoever holds the Commerce folder they sit in -- a group without
     * Commerce could not see these screens before this migration either, so
     * that loses nobody.
     *
     * menu_url IS the frontend route path -- the sidebar navigates to whatever
     * this column says -- so these values and the admin frontend's routes have
     * to move together. That is the whole cost of this migration, and it is
     * paid once.
     *
     * `service` is set here too: everything under Commerce is shop-service's
     * except QRIS, which is thirdparty-service's. That now includes Balance
     * and Statistics, left NULL while they had no screen -- they sit with
     * Selling, and an order-side screen without shop-service is a page of 502s
     * like the rest of them.
     *
     * Folders stay NULL, as add_service_to_menus_table explains: a folder goes
     * on its own once the outage empties it, and Masterdata holds QRIS beside
     * five shop rows, so marking it `shop` would hide QRIS whenever
     * shop-service went down.
     *
     * As with the other menu migrations, this is the top-up for a database
     * that already has its tree; MenuSeeder declares the same structure and is
     * what a fresh install builds it from. Hence the guard: with no Commerce
     * folder there is nothing to hang these on, and the whole branch is left
     * to the seeder.
     */
    public function up(): void
    {
        foreach (config('sites.ids') as $websiteId) {
            $commerceId = $this->folderId($websiteId, 'Commerce');

            // Fresh database: MenuSeeder builds the tree and places it right.
            if (!$commerceId) {
                continue;
            }

            // Who can see the folder these are going into. Read before
            // anything moves, so it is the access as it stood.
            $groupIds = DB::table('users_menugroupdetail')
                ->where('menu_id', $commerceId)
                ->pluck('usergroup_id');

            $folderIds = [];

            foreach (self::FOLDERS as $key => $folder) {
                $folderId = $this->folderId($websiteId, $folder['name_en']);

                if ($folderId) {
                    // Already there: make sure it hangs under Commerce, and
                    // leave the grants it has alone.
                    DB::table('menus')->where('id', $folderId)->update(['parent_id' => $commerceId]);
                } else {
                    $folderId = DB::table('menus')->insertGetId($folder + [
                        'website_id' => $websiteId,
                        'parent_id'  => $commerceId,
                        'menu_url'   => '',
                        'menu_type'  => 'FOLDER',
                        'service'    => null,
                    ]);

                    $this->grant($folderId, $groupIds);
                }

                $folderIds[$key] = $folderId;
            }

            // The eight screens that were loose in Commerce.
            foreach (self::MOVES as $old => $move) {
                $this->move($websiteId, $old, [
                    'parent_id' => $folderIds[$move['folder']],
                    'menu_url'  => $move['menu_url'],
                    'service'   => $move['service'],
                ]);
            }

            /*
             * Transaction moves whole: the folder itself, then the paths of
             * the three screens under it. Those keep their parent, so they
             * travel with the folder -- only the urls change, because the
             * folder is a level deeper than it was.
             */
            $transactionId = $this->folderId($websiteId, 'Transaction');

            if ($transactionId) {
                DB::table('menus')->where('id', $transactionId)->update(['parent_id' => $commerceId]);

                foreach (self::TRANSACTION as $old => $move) {
                    $this->move($websiteId, $old, $move);
                }
            }
        }
    }

    /**
     * Reverse: the eight screens loose in Commerce again, Transaction back at
     * the top level, the two new folders gone.
     *
     * Keyed on menu_url rather than on ids, as the other menu migrations are,
     * because the ids differ per website -- one tree each.
     */
    public function down(): void
    {
        foreach (config('sites.ids') as $websiteId) {
            $commerceId = $this->folderId($websiteId, 'Commerce');

            if (!$commerceId) {
                continue;
            }

            foreach (self::MOVES as $old => $move) {
                $this->move($websiteId, $move['menu_url'], [
                    'parent_id' => $commerceId,
                    'menu_url'  => $old,
                    'service'   => $move['service'],
                ]);
            }

            $transactionId = $this->folderId($websiteId, 'Transaction');

            if ($transactionId) {
                DB::table('menus')->where('id', $transactionId)->update(['parent_id' => null]);

                foreach (self::TRANSACTION as $old => $move) {
                    $this->move($websiteId, $move['menu_url'], [
                        'menu_url' => $old,
                        // Balance and Statistics were unclassified before they
                        // joined Selling under Commerce; putting them back
                        // means putting that back too.
                        'service'  => array_key_exists($old, self::WAS) ? self::WAS[$old] : $move['service'],
                    ]);
                }
            }
        }

        // The folders last, once nothing hangs under them: the grants first,
        // or the foreign key bites.
        foreach (self::FOLDERS as $folder) {
            $folderIds = DB::table('menus')
                ->where('menu_type', 'FOLDER')
                ->where('name_en', $folder['name_en'])
                ->pluck('id');

            if ($folderIds->isEmpty()) {
                continue;
            }

            DB::table('users_menugroupdetail')->whereIn('menu_id', $folderIds)->delete();
            DB::table('menus')->whereIn('id', $folderIds)->delete();
        }
    }

    /**
     * The folders Commerce gains. Transaction is not here: it exists already
     * and is moved, not created.
     */
    protected const FOLDERS = [
        'masterdata' => ['name_in' => 'Data Master', 'name_en' => 'Masterdata', 'menu_icon' => 'fas fa-cubes'],
        'price'      => ['name_in' => 'Harga',       'name_en' => 'Price',      'menu_icon' => 'fas fa-tags'],
    ];

    /**
     * Old url => where it goes. Read in both directions, which is why the old
     * url is the key: up() moves from it, down() moves back to it.
     */
    protected const MOVES = [
        'commerce/service'       => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/service',    'service' => 'shop'],
        'commerce/categories'    => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/categories', 'service' => 'shop'],
        'commerce/product'       => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/product',    'service' => 'shop'],
        'commerce/package'       => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/package',    'service' => 'shop'],
        'commerce/bank'          => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/bank',       'service' => 'shop'],
        // The one exception, here as everywhere: the codes and the Qrisly
        // credentials that registered them are thirdparty-service's.
        'commerce/qris'          => ['folder' => 'masterdata', 'menu_url' => 'commerce/masterdata/qris',       'service' => 'thirdparty'],
        'commerce/deliveryprice' => ['folder' => 'price',      'menu_url' => 'commerce/price/deliveryprice',   'service' => 'shop'],
        'commerce/othercharge'   => ['folder' => 'price',      'menu_url' => 'commerce/price/othercharge',     'service' => 'shop'],
    ];

    /**
     * Transaction's own screens: same folder, one level deeper, so only the
     * path and the service change.
     */
    protected const TRANSACTION = [
        'transaction/selling'    => ['menu_url' => 'commerce/transaction/selling',    'service' => 'shop'],
        'transaction/balance'    => ['menu_url' => 'commerce/transaction/balance',    'service' => 'shop'],
        'transaction/statistics' => ['menu_url' => 'commerce/transaction/statistics', 'service' => 'shop'],
    ];

    /** What down() has to put back: the two screens that had no service. */
    protected const WAS = [
        'transaction/balance'    => null,
        'transaction/statistics' => null,
    ];

    /** One website's folder by its English name, or null. */
    protected function folderId($websiteId, string $name)
    {
        return DB::table('menus')
            ->where('website_id', $websiteId)
            ->where('menu_type', 'FOLDER')
            ->where('name_en', $name)
            ->value('id');
    }

    /**
     * Update the row this website holds at $url, if it is still there.
     *
     * A missing row is not a failure: the menu may have been moved by an
     * earlier run of this migration, or never have existed on this
     * installation. Either way there is nothing to move and nothing to insert
     * -- creating menus is the seeder's job.
     */
    protected function move($websiteId, string $url, array $values): void
    {
        DB::table('menus')
            ->where('website_id', $websiteId)
            ->where('menu_url', $url)
            ->update($values);
    }

    protected function grant($menuId, $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            DB::table('users_menugroupdetail')->insert([
                'usergroup_id' => $groupId,
                'menu_id'      => $menuId,
            ]);
        }
    }
};
