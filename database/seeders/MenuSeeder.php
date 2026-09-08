<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the full admin menu tree for every website in config('sites.ids'),
     * so a new website (3, EV Charging Solution) gets its own sidebar. Parent
     * links are resolved per website via a key map, so each website's children
     * point at that website's own folders.
     *
     * Rows are keyed on (website_id, name_en) and updated in place, so running
     * this again tops up the missing menus instead of duplicating the tree --
     * important because users_menugroupdetail rows point at menu ids.
     *
     * This is the whole list, and the place to add to. About and Landing
     * Sections were added by migrations instead (add_about_menu,
     * add_section_menu), which is why they were missing here -- and why they
     * ended up at the top level rather than inside the Website folder they
     * name as their parent: a migration runs before any seeder, so the folder
     * this seeder creates did not exist yet and the parent lookup found
     * nothing. Declaring them here fixes their place on the next run, because
     * the key finds the rows the migrations already made and updates them
     * rather than inserting a second pair -- so the ids, and every grant
     * pointing at them, survive.
     *
     * A new menu belongs in this array. A migration that inserts one has to
     * resolve its own parent, and cannot, until the tree exists.
     *
     * The transaction-side menus (Transactions, Banks, Delivery Prices, Other
     * Charges) stay in the tree on purpose: the shop front no longer sells,
     * but the back office must keep managing what was already sold.
     *
     * @return void
     */
    public function run()
    {
        $menus = [
            ['key' => 'dashboard',   'parent' => null,              'name_in' => 'Dashboard',                   'name_en' => 'Dashboard',          'menu_url' => 'dashboard',        'menu_icon' => 'fas fa-table',            'menu_type' => 'MENU'],
            ['key' => 'website',     'parent' => null,              'name_in' => 'Website',                     'name_en' => 'Website',            'menu_url' => '',                 'menu_icon' => 'far fa-window-restore',   'menu_type' => 'FOLDER'],
            ['key' => 'web_setting', 'parent' => 'website',         'name_in' => 'Pengaturan Website',          'name_en' => 'Website Setting',    'menu_url' => 'website/setting',  'menu_icon' => 'fas fa-chalkboard',       'menu_type' => 'MENU'],
            ['key' => 'banner',      'parent' => 'website',         'name_in' => 'Banner',                      'name_en' => 'Banner',             'menu_url' => 'website/banner',   'menu_icon' => 'far fa-images',           'menu_type' => 'MENU'],
            ['key' => 'content',     'parent' => 'website',         'name_in' => 'Konten',                      'name_en' => 'Content',            'menu_url' => 'website/content',  'menu_icon' => 'fas fa-newspaper',        'menu_type' => 'MENU'],
            ['key' => 'about',       'parent' => 'website',         'name_in' => 'Tentang Kami',                'name_en' => 'About',              'menu_url' => 'website/about',    'menu_icon' => 'fas fa-address-card',     'menu_type' => 'MENU'],
            ['key' => 'section',     'parent' => 'website',         'name_in' => 'Bagian Halaman',              'name_en' => 'Landing Sections',   'menu_url' => 'website/section',  'menu_icon' => 'fas fa-stream',           'menu_type' => 'MENU'],
            ['key' => 'client',      'parent' => 'website',         'name_in' => 'Logo Klien',                  'name_en' => 'Client Logos',       'menu_url' => 'website/client',   'menu_icon' => 'fas fa-handshake',        'menu_type' => 'MENU'],
            ['key' => 'style',       'parent' => 'website',         'name_in' => 'Pengaturan Tampilan Utama',   'name_en' => 'Main Style Setting', 'menu_url' => 'website/style',    'menu_icon' => 'fas fa-fill-drip',        'menu_type' => 'MENU'],
            ['key' => 'settings',    'parent' => null,              'name_in' => 'Pengaturan',                  'name_en' => 'Settings',           'menu_url' => '',                 'menu_icon' => 'fas fa-cogs',             'menu_type' => 'FOLDER'],
            ['key' => 'user',        'parent' => 'settings',        'name_in' => 'Pengguna',                    'name_en' => 'Users',              'menu_url' => 'master/user',      'menu_icon' => 'fas fa-user',             'menu_type' => 'MENU'],
            ['key' => 'groupmenu',   'parent' => 'settings',        'name_in' => 'Grup Hak Akses',              'name_en' => 'User Access Group',  'menu_url' => 'master/groupmenu', 'menu_icon' => 'fas fa-users',            'menu_type' => 'MENU'],
            ['key' => 'inbox',       'parent' => null,              'name_in' => 'Pesan Masuk',                 'name_en' => 'Inbox',              'menu_url' => 'message',          'menu_icon' => 'fa fa-envelope-open-text', 'menu_type' => 'MENU'],
            ['key' => 'transaction', 'parent' => null,              'name_in' => 'Transaksi',                   'name_en' => 'Transactions',       'menu_url' => 'master/transaction', 'menu_icon' => 'fas fa-receipt',        'menu_type' => 'MENU'],
            // The seeder's own handle for this folder, named as 'parent' by the
            // seven rows below. It is not stored anywhere -- rows are matched
            // on name_en -- so renaming it costs nothing and keeps the code
            // reading the way the sidebar does.
            //
            // The menu_urls below are commerce/*, matching the folder and the
            // vue-vite module: a menu_url IS the frontend route path, read off
            // the row at navigation time, so these and commerce/routes.ts move
            // together (see rename_master_menu_urls_to_commerce).
            //
            // master/user and master/groupmenu above keep their paths -- they
            // are Settings, not commerce -- and so does master/transaction.
            ['key' => 'commerce',    'parent' => null,              'name_in' => 'Perdagangan',                 'name_en' => 'Commerce',           'menu_url' => '',                 'menu_icon' => 'fas fa-database',         'menu_type' => 'FOLDER'],
            ['key' => 'service',     'parent' => 'commerce',        'name_in' => 'Layanan',                     'name_en' => 'Services',           'menu_url' => 'commerce/service', 'menu_icon' => 'fas fa-hand-holding',     'menu_type' => 'MENU'],
            ['key' => 'categories',  'parent' => 'commerce',        'name_in' => 'Kategori',                    'name_en' => 'Categories',         'menu_url' => 'commerce/categories', 'menu_icon' => 'fas fa-th-large',      'menu_type' => 'MENU'],
            ['key' => 'products',    'parent' => 'commerce',        'name_in' => 'Produk',                      'name_en' => 'Products',           'menu_url' => 'commerce/product', 'menu_icon' => 'fas fa-gifts',            'menu_type' => 'MENU'],
            ['key' => 'packages',    'parent' => 'commerce',        'name_in' => 'Paket',                       'name_en' => 'Packages',           'menu_url' => 'commerce/package', 'menu_icon' => 'fas fa-box-open',         'menu_type' => 'MENU'],
            ['key' => 'deliveryprice','parent' => 'commerce',       'name_in' => 'Harga Pengiriman',            'name_en' => 'Delivery Prices',    'menu_url' => 'commerce/deliveryprice', 'menu_icon' => 'fas fa-truck',      'menu_type' => 'MENU'],
            ['key' => 'othercharge', 'parent' => 'commerce',        'name_in' => 'Biaya Lainnya',               'name_en' => 'Other Charges',      'menu_url' => 'commerce/othercharge', 'menu_icon' => 'fas fa-coins',        'menu_type' => 'MENU'],
            ['key' => 'bank',        'parent' => 'commerce',        'name_in' => 'Bank',                        'name_en' => 'Banks',              'menu_url' => 'commerce/bank',    'menu_icon' => 'fas fa-university',       'menu_type' => 'MENU'],
        ];

        // The websites table lives in website-service, so the ids come from
        // config instead (see config/sites.php).
        $websiteIds = config('sites.ids');

        foreach ($websiteIds as $websiteId) {
            $idMap = []; // key => menu id (per website)
            $added = 0;

            foreach ($menus as $menu) {
                $key = ['website_id' => $websiteId, 'name_en' => $menu['name_en']];

                $existing = DB::table('menus')->where($key)->value('id');

                DB::table('menus')->updateOrInsert($key, [
                    'name_in'   => $menu['name_in'],
                    'parent_id' => $menu['parent'] ? ($idMap[$menu['parent']] ?? null) : null,
                    'menu_url'  => $menu['menu_url'],
                    'menu_icon' => $menu['menu_icon'],
                    'menu_type' => $menu['menu_type'],
                ]);

                if (!$existing) {
                    $added++;
                }

                $idMap[$menu['key']] = DB::table('menus')->where($key)->value('id');
            }

            $this->command->info("  website $websiteId menus: " . count($menus) . " total, $added new");
        }
    }
}
