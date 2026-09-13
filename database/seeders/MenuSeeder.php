<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the admin menu tree. One tree, shared by every website: the
     * sidebar is the admin application, and the admin application is the same
     * whichever site it is administering. It used to be seeded once per site
     * in config('sites.ids') and nothing ever made the copies differ, so
     * drop_website_id_from_menus folded them into this one and took the column
     * with them. What scopes an admin is the grant -- a group belongs to a
     * website, and a user reaches a screen only through their group -- and
     * what a screen then reads is scoped by the website_id on the token.
     *
     * Rows are keyed on name_en and updated in place, so running this again
     * tops up the missing menus instead of duplicating the tree -- important
     * because users_menugroupdetail rows point at menu ids.
     *
     * Parent links are resolved through a key map filled as the rows are
     * written, so a parent has to be declared before its children.
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
     * 'service' names the service whose absence makes the screen useless --
     * a key from config/gateway.php -- and the sidebar leaves the row out
     * while that service is not answering (MenuService::getMenus). null means
     * the gateway answers the screen itself, which is the right value for
     * Users, Access Groups, the Dashboard and every folder: a folder goes
     * when the last thing inside it does. Set it whenever a row is added,
     * because null is also what an unclassified row gets, and an
     * unclassified row is one that stays in the sidebar during an outage.
     *
     * The transaction-side menus (the Transaction folder, Banks, Delivery
     * Prices, Other Charges) stay in the tree on purpose: the shop front no
     * longer sells, but the back office must keep managing what was already
     * sold.
     *
     * @return void
     */
    public function run()
    {
        $menus = [
            ['key' => 'dashboard',   'parent' => null,              'name_in' => 'Dashboard',                   'name_en' => 'Dashboard',          'menu_url' => 'dashboard',        'menu_icon' => 'fas fa-table',            'menu_type' => 'MENU', 'service' => null],
            ['key' => 'website',     'parent' => null,              'name_in' => 'Website',                     'name_en' => 'Website',            'menu_url' => '',                 'menu_icon' => 'far fa-window-restore',   'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'web_setting', 'parent' => 'website',         'name_in' => 'Pengaturan Website',          'name_en' => 'Website Setting',    'menu_url' => 'website/setting',  'menu_icon' => 'fas fa-chalkboard',       'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'banner',      'parent' => 'website',         'name_in' => 'Banner',                      'name_en' => 'Banner',             'menu_url' => 'website/banner',   'menu_icon' => 'far fa-images',           'menu_type' => 'MENU', 'service' => 'website'],
            // The promotional slider, next to Banner: the other screen that
            // puts images on the landing page. A banner is the site's own
            // hero and stays; a campaign is temporary and links away from the
            // page, which is why they are separate screens over separate
            // tables in website-service.
            ['key' => 'campaign',    'parent' => 'website',         'name_in' => 'Kampanye',                    'name_en' => 'Campaign',           'menu_url' => 'website/campaign', 'menu_icon' => 'fas fa-bullhorn',         'menu_type' => 'MENU', 'service' => 'website'],
            // Under Website, but answered by shop-service -- the first row in
            // the tree whose folder and service disagree, and deliberately. A
            // highlight changes nothing about a product, only which products
            // the page leads with, so an admin looks for it beside Banner and
            // Campaign; the table and the products behind it are
            // shop-service's, so that is what has to be up for the screen to
            // work. The folder stays null and only this row goes when
            // shop-service does.
            ['key' => 'highlight',   'parent' => 'website',         'name_in' => 'Produk Unggulan',             'name_en' => 'Product Highlight',  'menu_url' => 'website/product-highlight', 'menu_icon' => 'fas fa-star',    'menu_type' => 'MENU', 'service' => 'shop'],
            ['key' => 'content',     'parent' => 'website',         'name_in' => 'Konten',                      'name_en' => 'Content',            'menu_url' => 'website/content',  'menu_icon' => 'fas fa-newspaper',        'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'about',       'parent' => 'website',         'name_in' => 'Tentang Kami',                'name_en' => 'About',              'menu_url' => 'website/about',    'menu_icon' => 'fas fa-address-card',     'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'section',     'parent' => 'website',         'name_in' => 'Bagian Halaman',              'name_en' => 'Landing Sections',   'menu_url' => 'website/section',  'menu_icon' => 'fas fa-stream',           'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'client',      'parent' => 'website',         'name_in' => 'Logo Klien',                  'name_en' => 'Client Logos',       'menu_url' => 'website/client',   'menu_icon' => 'fas fa-handshake',        'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'style',       'parent' => 'website',         'name_in' => 'Pengaturan Tampilan Utama',   'name_en' => 'Main Style Setting', 'menu_url' => 'website/style',    'menu_icon' => 'fas fa-fill-drip',        'menu_type' => 'MENU', 'service' => 'website'],
            ['key' => 'settings',    'parent' => null,              'name_in' => 'Pengaturan',                  'name_en' => 'Settings',           'menu_url' => '',                 'menu_icon' => 'fas fa-cogs',             'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'user',        'parent' => 'settings',        'name_in' => 'Pengguna',                    'name_en' => 'Users',              'menu_url' => 'master/user',      'menu_icon' => 'fas fa-user',             'menu_type' => 'MENU', 'service' => null],
            ['key' => 'groupmenu',   'parent' => 'settings',        'name_in' => 'Grup Hak Akses',              'name_en' => 'User Access Group',  'menu_url' => 'master/groupmenu', 'menu_icon' => 'fas fa-users',            'menu_type' => 'MENU', 'service' => null],
            ['key' => 'inbox',       'parent' => null,              'name_in' => 'Pesan Masuk',                 'name_en' => 'Inbox',              'menu_url' => 'message',          'menu_icon' => 'fa fa-envelope-open-text', 'menu_type' => 'MENU', 'service' => 'website'],
            // Everything commercial, in one folder holding three: the
            // reference data, what delivery and the extras cost, and the
            // orders they end up on.
            //
            //   Commerce/
            //     Masterdata/   what is for sale, and where money arrives
            //     Price/        what delivery and the extras cost
            //     Transaction/  what was actually sold
            //
            // restructure_commerce_menus moves an existing database into this
            // shape; the rows below are matched on name_en, so a re-seed finds
            // the same rows that migration moved and leaves their ids -- and
            // every grant pointing at them -- alone.
            //
            // A menu_url IS the frontend route path, read off the row at
            // navigation time, so these paths and the admin frontend's routes
            // move together (see rename_master_menu_urls_to_commerce, and the
            // restructure that nested them a level deeper).
            //
            // master/user and master/groupmenu above keep their paths: they
            // are Settings, not commerce.
            //
            // Every screen in here is shop-service's except QRIS -- the codes,
            // and the Qrisly credentials that registered them, are
            // thirdparty-service's. The folders stay null on purpose: a folder
            // disappears once an outage empties it, and marking Masterdata
            // 'shop' would take QRIS down with the rest of them.
            ['key' => 'commerce',    'parent' => null,              'name_in' => 'Perdagangan',                 'name_en' => 'Commerce',           'menu_url' => '',                                'menu_icon' => 'fas fa-database',      'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'masterdata',  'parent' => 'commerce',        'name_in' => 'Data Master',                 'name_en' => 'Masterdata',         'menu_url' => '',                                'menu_icon' => 'fas fa-cubes',         'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'service',     'parent' => 'masterdata',      'name_in' => 'Layanan',                     'name_en' => 'Services',           'menu_url' => 'commerce/masterdata/service',     'menu_icon' => 'fas fa-hand-holding',  'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'categories',  'parent' => 'masterdata',      'name_in' => 'Kategori',                    'name_en' => 'Categories',         'menu_url' => 'commerce/masterdata/categories',  'menu_icon' => 'fas fa-th-large',      'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'products',    'parent' => 'masterdata',      'name_in' => 'Produk',                      'name_en' => 'Products',           'menu_url' => 'commerce/masterdata/product',     'menu_icon' => 'fas fa-gifts',         'menu_type' => 'MENU',   'service' => 'shop'],
            // Moderation for what buyers wrote about a product: list, hide,
            // remove. Beside Products because that is what a seller is looking
            // for when they come here -- not under Transaction, though every
            // review comes from an order: the order is how a review is proved
            // rather than what it is about. No create or edit exists on the
            // API, so a shop cannot write its own.
            ['key' => 'review',      'parent' => 'masterdata',      'name_in' => 'Ulasan Produk',               'name_en' => 'Product Reviews',    'menu_url' => 'commerce/masterdata/review',      'menu_icon' => 'fas fa-comment-dots',  'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'packages',    'parent' => 'masterdata',      'name_in' => 'Paket',                       'name_en' => 'Packages',           'menu_url' => 'commerce/masterdata/package',     'menu_icon' => 'fas fa-box-open',      'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'bank',        'parent' => 'masterdata',      'name_in' => 'Bank',                        'name_en' => 'Banks',              'menu_url' => 'commerce/masterdata/bank',        'menu_icon' => 'fas fa-university',    'menu_type' => 'MENU',   'service' => 'shop'],
            // Next to Banks: the same thing to an admin, somewhere a
            // customer's money arrives. That thirdparty-service answers it
            // rather than shop-service is nothing the sidebar shows -- but it
            // is what the service column says, so an outage there takes this
            // row and leaves the rest of Masterdata standing.
            ['key' => 'qris',        'parent' => 'masterdata',      'name_in' => 'QRIS',                        'name_en' => 'QRIS',               'menu_url' => 'commerce/masterdata/qris',        'menu_icon' => 'fas fa-qrcode',        'menu_type' => 'MENU',   'service' => 'thirdparty'],
            ['key' => 'price',       'parent' => 'commerce',        'name_in' => 'Harga',                       'name_en' => 'Price',              'menu_url' => '',                                'menu_icon' => 'fas fa-tags',          'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'deliveryprice','parent' => 'price',          'name_in' => 'Harga Pengiriman',            'name_en' => 'Delivery Prices',    'menu_url' => 'commerce/price/deliveryprice',    'menu_icon' => 'fas fa-truck',         'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'othercharge', 'parent' => 'price',           'name_in' => 'Biaya Lainnya',               'name_en' => 'Other Charges',      'menu_url' => 'commerce/price/othercharge',      'menu_icon' => 'fas fa-coins',         'menu_type' => 'MENU',   'service' => 'shop'],
            // The back office for what was sold. Selling is the order list --
            // read one, move its status on, file a document against it -- and
            // is the row that used to be the top-level 'Transactions' menu:
            // move_transaction_menu_into_folder renames that row in place, so
            // the key below finds it and updates it rather than inserting a
            // second one, and every access grant pointing at its id survives.
            // restructure_commerce_menus then moved the folder in here whole,
            // children and all.
            //
            // Balance and Statistics have no screen yet. They are declared
            // here anyway, because the sidebar is what says a screen is coming
            // and the frontend's placeholder is what an unbuilt one resolves
            // to. They carry 'shop' with the rest of the folder now: an
            // order-side screen without shop-service is a page of 502s
            // whether or not it has been built.
            ['key' => 'transaction', 'parent' => 'commerce',        'name_in' => 'Transaksi',                   'name_en' => 'Transaction',        'menu_url' => '',                                'menu_icon' => 'fas fa-receipt',       'menu_type' => 'FOLDER', 'service' => null],
            ['key' => 'selling',     'parent' => 'transaction',     'name_in' => 'Penjualan',                   'name_en' => 'Selling',            'menu_url' => 'commerce/transaction/selling',    'menu_icon' => 'fas fa-cash-register', 'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'balance',     'parent' => 'transaction',     'name_in' => 'Saldo',                       'name_en' => 'Balance',            'menu_url' => 'commerce/transaction/balance',    'menu_icon' => 'fas fa-wallet',        'menu_type' => 'MENU',   'service' => 'shop'],
            ['key' => 'statistics',  'parent' => 'transaction',     'name_in' => 'Statistik',                   'name_en' => 'Statistics',         'menu_url' => 'commerce/transaction/statistics', 'menu_icon' => 'fas fa-chart-line',    'menu_type' => 'MENU',   'service' => 'shop'],
        ];

        $idMap = []; // key => menu id
        $added = 0;

        foreach ($menus as $menu) {
            $key = ['name_en' => $menu['name_en']];

            $existing = DB::table('menus')->where($key)->value('id');

            DB::table('menus')->updateOrInsert($key, [
                'name_in'   => $menu['name_in'],
                'parent_id' => $menu['parent'] ? ($idMap[$menu['parent']] ?? null) : null,
                'menu_url'  => $menu['menu_url'],
                'menu_icon' => $menu['menu_icon'],
                'menu_type' => $menu['menu_type'],
                'service'   => $menu['service'],
            ]);

            if (!$existing) {
                $added++;
            }

            $idMap[$menu['key']] = DB::table('menus')->where($key)->value('id');
        }

        $this->command->info('  menus: ' . count($menus) . " total, $added new");
    }
}
