<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn the single "Transactions" menu into a folder with three screens
     * under it: Selling, Balance and Statistics.
     *
     * The existing row is moved rather than replaced. Its id is what the
     * access grants in users_menugroupdetail point at, so inserting a fresh
     * "Selling" row and leaving the old one behind would strand every grant on
     * a menu nobody navigates to any more -- and leave a stale Transactions
     * link at the top level. Renaming it in place keeps both the id and the
     * grants, and the sidebar simply finds it one level deeper.
     *
     * The folder needs a grant of its own, and that is the part worth being
     * careful about: MenuRepository::childrenForRole() gates every level, so a
     * child whose parent the group cannot see is a child nobody can reach.
     * Granting the folder to exactly the groups that held the moved row is
     * what makes this a move rather than a change of access -- an admin who
     * could see Transactions sees the folder, and one who could not gains
     * nothing.
     *
     * The two new screens are granted the same way, and neither has a view
     * yet: the sidebar entry resolves to vue-vite's placeholder until one is
     * written, which is what makes an unbuilt screen visibly unbuilt rather
     * than broken.
     *
     * menu_url IS the frontend route path -- the sidebar navigates to whatever
     * this column says -- so `transaction/selling` here and vue-vite's
     * commerce/routes.ts have to move together.
     *
     * As with add_qris_menu, this is the top-up for a database that already
     * has its tree; the same tree is declared in MenuSeeder, which is what a
     * fresh install builds it from. Hence the guard: with no Transactions row
     * to move, this does nothing and leaves the whole branch to the seeder.
     */
    public function up(): void
    {
        foreach (config('sites.ids') as $websiteId) {
            // Already moved: nothing to do, and nothing to insert twice.
            if ($this->menuId($websiteId, 'transaction/selling')) {
                continue;
            }

            $sellingId = $this->menuId($websiteId, 'master/transaction');

            // Fresh database: MenuSeeder builds the branch and places it right.
            if (!$sellingId) {
                continue;
            }

            // Whoever could see the old menu, and only them.
            $groupIds = DB::table('users_menugroupdetail')
                ->where('menu_id', $sellingId)
                ->pluck('usergroup_id');

            $folderId = DB::table('menus')
                ->where('website_id', $websiteId)
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Transaction')
                ->value('id');

            if (!$folderId) {
                $folderId = DB::table('menus')->insertGetId([
                    'website_id' => $websiteId,
                    'parent_id'  => null,
                    'name_in'    => 'Transaksi',
                    'name_en'    => 'Transaction',
                    'menu_url'   => '',
                    'menu_icon'  => 'fas fa-receipt',
                    'menu_type'  => 'FOLDER',
                ]);

                $this->grant($folderId, $groupIds);
            }

            // The move: same row, same id, same grants, new place and name.
            DB::table('menus')->where('id', $sellingId)->update([
                'parent_id' => $folderId,
                'name_in'   => 'Penjualan',
                'name_en'   => 'Selling',
                'menu_url'  => 'transaction/selling',
                'menu_icon' => 'fas fa-cash-register',
                'menu_type' => 'MENU',
            ]);

            foreach (self::PENDING as $menu) {
                if ($this->menuId($websiteId, $menu['menu_url'])) {
                    continue;
                }

                $this->grant(
                    DB::table('menus')->insertGetId($menu + [
                        'website_id' => $websiteId,
                        'parent_id'  => $folderId,
                        'menu_type'  => 'MENU',
                    ]),
                    $groupIds
                );
            }
        }
    }

    /**
     * Reverse: Transactions back at the top level, the rest gone.
     *
     * Keyed on menu_url rather than on ids, as
     * rename_master_menu_urls_to_commerce is, because the ids differ per
     * website -- one tree each.
     */
    public function down(): void
    {
        DB::table('menus')->where('menu_url', 'transaction/selling')->update([
            'parent_id' => null,
            'name_in'   => 'Transaksi',
            'name_en'   => 'Transactions',
            'menu_url'  => 'master/transaction',
            'menu_icon' => 'fas fa-receipt',
        ]);

        // The two unbuilt screens, then the folder they hung under -- in that
        // order, so the folder is empty by the time it goes.
        $this->remove(
            DB::table('menus')
                ->whereIn('menu_url', array_column(self::PENDING, 'menu_url'))
                ->pluck('id')
        );

        $this->remove(
            DB::table('menus')
                ->where('menu_type', 'FOLDER')
                ->where('name_en', 'Transaction')
                ->pluck('id')
        );
    }

    /**
     * The screens the folder gains, neither of them built yet.
     *
     * Declared once because up() inserts them and down() removes them by the
     * same url, and a second list is a second thing to keep in step.
     */
    protected const PENDING = [
        ['name_in' => 'Saldo',     'name_en' => 'Balance',    'menu_url' => 'transaction/balance',    'menu_icon' => 'fas fa-wallet'],
        ['name_in' => 'Statistik', 'name_en' => 'Statistics', 'menu_url' => 'transaction/statistics', 'menu_icon' => 'fas fa-chart-line'],
    ];

    /** One website's menu with this url, or null. */
    protected function menuId($websiteId, string $url)
    {
        return DB::table('menus')
            ->where('website_id', $websiteId)
            ->where('menu_url', $url)
            ->value('id');
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

    /** A menu and the grants pointing at it: the grants first, or the FK bites. */
    protected function remove($menuIds): void
    {
        if ($menuIds->isEmpty()) {
            return;
        }

        DB::table('users_menugroupdetail')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
