<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which service a menu's screen cannot work without.
     *
     * The sidebar is the gateway's, but most of the screens behind it are not:
     * Products is shop-service, Banner is website-service, QRIS is
     * thirdparty-service. When one of those is down, the menu is still granted
     * and still rendered, and clicking it opens a screen whose every call
     * answers 502 -- so the outage reaches the admin as a broken page rather
     * than as a missing one.
     *
     * This column is what lets the sidebar leave it out instead. It holds a
     * key from config/gateway.php's `services` -- website, shop, thirdparty --
     * and is read against GET /api/status when the sidebar is built (see
     * MenuService).
     *
     * NULL means the gateway itself answers the screen: Users, Access Groups,
     * Dashboard and every folder. Those have nothing to be unavailable, so
     * they are always shown -- which is also the safe default for a row nobody
     * has classified yet.
     *
     * Folders are deliberately left NULL even where all their children belong
     * to one service. A folder disappears on its own once everything inside it
     * has, and Commerce holds both shop rows and a thirdparty one, so marking
     * it would hide QRIS whenever shop-service went down.
     */
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('service', 50)->nullable()->after('menu_type');
        });

        // The tree as it stands today. Keyed on menu_url rather than name_en
        // because the url is what says which service answers the screen --
        // it is the frontend route, and the route's calls are what fail.
        $services = [
            'website' => [
                'website/setting',
                'website/banner',
                'website/content',
                'website/about',
                'website/section',
                'website/client',
                'website/style',
                // The contact form's messages: forwarded to website-service
                // like the rest of the site's own content.
                'message',
            ],
            'shop' => [
                'commerce/service',
                'commerce/categories',
                'commerce/product',
                'commerce/package',
                'commerce/deliveryprice',
                'commerce/othercharge',
                'commerce/bank',
                'transaction/selling',
            ],
            // The codes and the Qrisly credentials that registered them.
            'thirdparty' => [
                'commerce/qris',
            ],
        ];

        foreach ($services as $service => $urls) {
            DB::table('menus')->whereIn('menu_url', $urls)->update(['service' => $service]);
        }

        /*
         * Left NULL on purpose, and not by omission:
         *
         *   dashboard, master/user, master/groupmenu   answered here
         *   every FOLDER                               see above
         *   transaction/balance, transaction/statistics
         *
         * The last two have no screen yet -- the sidebar is what says one is
         * coming. Classifying them now would be guessing at which service will
         * answer them, and guessing wrong hides a menu for an outage that has
         * nothing to do with it. They get a service when they get a screen.
         */
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('service');
        });
    }
};
