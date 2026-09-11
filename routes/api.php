<?php

use App\Http\Controllers\Api\Admin\GroupMenuController;
use App\Http\Controllers\Api\Admin\ShopAdminController;
use App\Http\Controllers\Api\Admin\ThirdpartyAdminController;
use App\Http\Controllers\Api\Admin\WebsiteAdminController;
use App\Http\Controllers\Api\Admin\MenuController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\PublicQrisController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\StatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Gateway
|--------------------------------------------------------------------------
|
| The only host the frontend talks to. It owns what the services deliberately
| do not:
|
|   - accounts, sessions and the JWT everything else runs on
|   - access groups and the admin menu tree
|   - the public document root: site assets and uploaded files
|
| Nothing else. A table that is not about who is calling belongs to the service
| that owns the domain -- the QRIS codes moved to thirdparty-service, beside
| the Qrisly credentials that register them.
|
| Behind it, each service holds its own database and trusts the token:
|
|   website-service     site identity, theming, page layout, content
|   shop-service        catalogue, carts, checkout, orders
|   thirdparty-service  Omile TMS, RajaOngkir, Qrisly
|
| A service reads website_id and email off the token's claims (see
| App\Models\User::getJWTCustomClaims) and scopes every row by them, which is
| why none of them needs a users table.
|
| Forwarding is done by App\Services\Gateway\ServiceProxy, which relays the
| service's answer untouched; the base URLs are in config/gateway.php.
|
| Both halves are forwarded. The public reads -- /api/v1/* below, no token --
| are what let a browser open a site. The admin routes are behind 'auth:api'
| here, and each service verifies the same token again with VerifyJwt before
| answering, so a forwarded request has been vouched for twice.
*/

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
|
| Which services are answering.
|
| /up says this app is running and nothing more; the three services behind it
| are invisible from outside, so a service being down reaches the frontend as
| a 502 on whatever call needed it -- without saying which service, or whether
| the others are fine. This asks all of them at once and answers plainly.
|
| No token: it exposes no URL, only names and states, and a status page that
| needs a login is no use during an outage that includes the login. 200 while
| everything is up, 503 as soon as anything is not, so a monitor can watch the
| status code alone.
|
| Throttled because each miss costs one outbound request per service; answers
| are reused for gateway.health.ttl seconds, so polling mostly costs nothing.
*/
Route::get('/status', StatusController::class)->middleware('throttle:30,1');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
|
| The only place a password is checked. Everything but login needs
| `Authorization: Bearer <token>`.
*/
Route::prefix('auth')->group(function () {
    // Throttled: this is the one endpoint where guessing repeatedly is the
    // attack. 10 attempts a minute per IP, which no human login ever needs.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        // Trades a valid token for a fresh one; the old one is blacklisted.
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Public storefront
|--------------------------------------------------------------------------
|
| No token: this is what a visitor's browser reads and writes before anyone
| has signed in. Two services answer it, split by what they own, and the URLs
| mirror the v1 group in each service's routes/api.php one for one so the
| files can be read against each other:
|
|   website-service   who the site is -- identity, theme, sections, articles
|   shop-service      what is for sale -- catalogue, basket, checkout, orders
|
| Which service a route goes to is visible in its controller and nowhere else;
| the frontend sees one origin either way. Three calls render a storefront:
|
|   GET /api/v1/portal                   which sites exist, and their slugs
|   GET /api/v1/sites/{website}/landing  everything that page shows
|   GET /api/v1/sites/{website}/catalog  services, products and packages
|
| Pass ?has_packages=1 (what shop-service answered) wherever the packages
| block's visibility matters -- the query string is carried across as it stands.
|
| shop-service's seller half (/api/admin/...) is deliberately not here: it has
| no frontend caller yet and belongs behind the JWT, so it is forwarded when
| the admin group below is wrapped in 'auth:api'.
*/
Route::prefix('v1')->group(function () {

    Route::get('/portal', [SiteController::class, 'portal']);
    Route::get('/sites', [SiteController::class, 'index']);

    // -> shop-service. The checkout address form, one level at a time. Not
    // website scoped: the region tables are the same whoever is selling.
    Route::prefix('regions')->group(function () {
        Route::get('/provinces', [ShopController::class, 'provinces']);
        Route::get('/cities/{provinceCode}', [ShopController::class, 'cities']);
        Route::get('/districts/{cityCode}', [ShopController::class, 'districts']);
        Route::get('/subdistricts/{districtCode}', [ShopController::class, 'subdistricts']);
    });

    Route::prefix('/sites/{website}')->whereNumber('website')->group(function () {
        Route::get('/', [SiteController::class, 'show']);
        Route::get('/landing', [SiteController::class, 'landing']);
        Route::get('/styles', [SiteController::class, 'styles']);
        Route::get('/sections', [SiteController::class, 'sections']);
        Route::get('/banners', [SiteController::class, 'banners']);
        Route::get('/abouts', [SiteController::class, 'abouts']);
        Route::get('/clients', [SiteController::class, 'clients']);
        Route::get('/contents', [SiteController::class, 'contents']);
        Route::get('/contents/{id}', [SiteController::class, 'content'])->whereNumber('id');
        Route::get('/contents/{id}/comments', [SiteController::class, 'comments'])->whereNumber('id');

        // The other public write, so the other route worth a limit. Tighter
        // than /contact: a person writes one comment and posts it, so five a
        // minute is already generous and a script wants far more.
        Route::post('/contents/{id}/comments', [SiteController::class, 'storeComment'])
            ->whereNumber('id')
            ->middleware('throttle:5,1');

        // The one public write, so the one public route worth a limit: a
        // contact form is what a spammer scripts. 20 a minute per IP is far
        // more than a person fills in and far less than a script wants.
        Route::post('/contact', [SiteController::class, 'contact'])
            ->middleware('throttle:20,1');

        /* ---- Catalogue -> shop-service ---- */

        Route::get('/catalog', [ShopController::class, 'catalog']);
        Route::get('/services', [ShopController::class, 'services']);
        Route::get('/products', [ShopController::class, 'products']);
        Route::get('/products/{id}', [ShopController::class, 'product'])->whereNumber('id');

        // The product page's view counter. A public write, so a limited one --
        // and looser than the comment box, because a visitor legitimately
        // opens several products in a row while browsing a catalogue.
        Route::post('/products/{id}/view', [ShopController::class, 'productView'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
        Route::get('/packages', [ShopController::class, 'packages']);

        /* ---- Basket -> shop-service ---- */

        // Kept against the cart_token cookie, which ServiceProxy carries in
        // both directions; there is no login and no user id behind it.
        Route::get('/cart', [ShopController::class, 'cart']);
        Route::post('/cart', [ShopController::class, 'saveCart']);
        Route::delete('/cart', [ShopController::class, 'clearCart']);

        /* ---- Checkout -> shop-service ---- */

        Route::get('/fare', [ShopController::class, 'fare']);
        Route::post('/checkout/confirm', [ShopController::class, 'confirmCheckout']);
        Route::post('/checkout', [ShopController::class, 'checkout']);

        /* ---- Payment ---- */

        // -> thirdparty-service, which holds the codes. The receipt itself is
        // shop-service's and cannot carry this, since services never call each
        // other -- so a receipt paid by QRIS asks for the two separately.
        Route::get('/qris', [PublicQrisController::class, 'active']);

        /* ---- Orders -> shop-service ---- */

        // Email plus the last digits of a phone, so a guessable pair: rate
        // limited here for the same reason /contact is, and more tightly,
        // because each attempt is a try at someone else's order.
        Route::post('/orders/lookup', [ShopController::class, 'lookupOrder'])
            ->middleware('throttle:10,1');

        // Addressed by its unguessable token, which is what keeps it public.
        Route::get('/receipt/{token}', [ShopController::class, 'receipt']);
        Route::post('/receipt/{token}/attachments', [ShopController::class, 'uploadAttachment']);

        /*
         * Has this order's QRIS been paid?
         *
         * Answered here rather than forwarded, for the usual reason: the token
         * names an order shop-service holds, and the payment behind it is
         * thirdparty-service's.
         *
         * Throttled because it is the one route on the storefront that costs
         * an upstream call per request -- Qrisly sends no webhook, so a receipt
         * page finds out by asking, and a page polling every second would ask
         * sixty times a minute. Twenty is a check every three seconds, which is
         * faster than anyone scans a code. A payment already settled answers
         * from the stored row without troubling Qrisly at all.
         *
         * Declared after /receipt/{token} so the literal segment is matched by
         * this route rather than swallowed as part of a token.
         */
        Route::get('/receipt/{token}/payment-status', [PublicQrisController::class, 'paymentStatus'])
            ->middleware('throttle:20,1');

        /*
         * There is deliberately no route here that raises a QRIS.
         *
         * A payment is generated once, as part of placing the order -- see
         * ShopController::checkout -- and the receipt above carries it under
         * `data.qris_payment`. Opening a receipt is a read: it shows the
         * payment that exists and never asks Qrisly for another, so no amount
         * of refreshing the page spends the seller's balance.
         */
    });
});

/*
|--------------------------------------------------------------------------
| Files
|--------------------------------------------------------------------------
|
| Reads are public: these are the images a visitor's browser renders. The
| write is not -- it files an upload under a fixed set of folders.
|
| {path} is greedy so nested paths resolve; only uploads/ and webassets/ are
| reachable, checked after the path is resolved.
*/
Route::prefix('files')->group(function () {
    Route::get('/{path}', [FileController::class, 'show'])->where('path', '.*');
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
|
| Accounts and access, scoped to the caller's own website.
|
| 'auth:api' is the JWT guard: it verifies the bearer token, checks it against
| the blacklist -- which only this app can do, the services having their own
| cache -- and resolves the User the claims were signed from. So this is the
| one place a token is checked against stored state; a forward from here
| carries the same token onward, and the service trusts its signature.
|
| The scope of every route below comes from that user, never from a header a
| caller could set.
*/
Route::prefix('admin')->middleware('auth:api')->group(function () {

    // The signed-in user's own sidebar.
    Route::get('/menus', [MenuController::class, 'index']);

    Route::post('/files', [FileController::class, 'store']);

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        // Active access groups, for the roles_code picker.
        Route::get('/groups', [UserController::class, 'groups']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    Route::prefix('access-groups')->group(function () {
        Route::get('/', [GroupMenuController::class, 'index']);
        // The menu tree a group's grants are picked from; ?group={id} adds
        // that group's current selection.
        Route::get('/menu-tree', [GroupMenuController::class, 'menuTree']);
        Route::post('/', [GroupMenuController::class, 'store']);
        Route::get('/{id}', [GroupMenuController::class, 'show']);
        Route::put('/{id}', [GroupMenuController::class, 'update']);
        Route::delete('/{id}', [GroupMenuController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Forwarded: website-service
    |--------------------------------------------------------------------------
    |
    | The site's own content -- what it looks like, what it says. Mirrors the
    | admin group in website-service/routes/api.php one for one, in the same
    | order, so the two can be read side by side.
    |
    | Update is POST, not PUT, wherever a file can be attached: PHP does not
    | parse a multipart body on PUT. ServiceProxy forwards those bodies as
    | multipart rather than re-encoding them.
    */

    Route::get('/website', WebsiteAdminController::class);
    Route::post('/website', WebsiteAdminController::class);

    Route::prefix('styles')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        // Before /{id}: a literal segment must not be read as an id.
        Route::post('/values', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::put('/{id}', WebsiteAdminController::class);
    });

    Route::prefix('sections')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::post('/order', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::put('/{id}', WebsiteAdminController::class);
    });

    Route::prefix('banners')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::post('/', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::post('/{id}', WebsiteAdminController::class);
        Route::delete('/{id}', WebsiteAdminController::class);
    });

    Route::prefix('contents')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::post('/', WebsiteAdminController::class);
        // Before /{id}: a literal segment must not be read as an id.
        // How the articles are being read -- ?days=N sets the window.
        Route::get('/stats', WebsiteAdminController::class);
        Route::get('/{id}/stats', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::post('/{id}', WebsiteAdminController::class);
        Route::delete('/{id}', WebsiteAdminController::class);
    });

    // Moderation for the comments visitors leave on an article: list them,
    // remove the ones that should not be there. Nothing edits one.
    Route::prefix('comments')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::delete('/{id}', WebsiteAdminController::class);
    });

    Route::prefix('abouts')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::post('/', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::post('/{id}', WebsiteAdminController::class);
        Route::delete('/{id}', WebsiteAdminController::class);
    });

    Route::prefix('clients')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::post('/', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
        Route::post('/{id}', WebsiteAdminController::class);
        Route::delete('/{id}', WebsiteAdminController::class);
    });

    // Read only: a contact form writes these, an admin reads them.
    Route::prefix('messages')->group(function () {
        Route::get('/', WebsiteAdminController::class);
        Route::get('/{id}', WebsiteAdminController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | Forwarded: shop-service
    |--------------------------------------------------------------------------
    |
    | The catalogue and the orders against it. Mirrors the admin group in
    | shop-service/routes/api.php one for one, in the same order.
    |
    | No id is constrained here, because none is constrained there: a
    | non-numeric id has to reach the service and get the service's own answer,
    | rather than a 404 the gateway invented.
    */

    Route::prefix('services')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::post('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', ShopAdminController::class);
        // The parent picker, which must not be read as an id.
        Route::get('/parents', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::put('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('products')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        // Before /{id}: a literal segment must not be read as an id.
        // How often the catalogue is looked at -- ?days=N sets the window.
        Route::get('/stats', ShopAdminController::class);
        Route::get('/{id}/stats', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::post('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('packages')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::get('/options', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::put('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('other-charges')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::put('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('banks')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::post('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    Route::prefix('delivery-prices')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::post('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::put('/{id}', ShopAdminController::class);
        Route::delete('/{id}', ShopAdminController::class);
    });

    // An order is placed by a customer, never created here: read it, and move
    // its status on.
    Route::prefix('transactions')->group(function () {
        Route::get('/', ShopAdminController::class);
        Route::get('/{id}', ShopAdminController::class);
        Route::put('/{id}/status', ShopAdminController::class);
        // Multipart; ServiceProxy forwards the file rather than re-encoding it.
        Route::post('/{id}/attachments', ShopAdminController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | Forwarded: thirdparty-service
    |--------------------------------------------------------------------------
    |
    | Not traffic: two writes an admin makes, each against an upstream this
    | installation does not own.
    */

    // Re-reads the courier's area list into the mapping table.
    Route::post('/shipping/mapping/sync', ThirdpartyAdminController::class);

    /*
    | The QRIS codes a website is paid into. Owned by thirdparty-service, with
    | the table and the Qrisly credentials that register them, so these are
    | relayed like any other admin resource -- the paths are identical on both
    | sides.
    |
    | POST is multipart (the image); ServiceProxy forwards it as multipart
    | rather than re-encoding it. Update is a plain PUT because the image
    | cannot be replaced: a different image is a different registration.
    */
    Route::prefix('qris')->group(function () {
        Route::get('/', ThirdpartyAdminController::class);
        Route::post('/', ThirdpartyAdminController::class);
        Route::get('/{id}', ThirdpartyAdminController::class)->whereNumber('id');
        Route::put('/{id}', ThirdpartyAdminController::class)->whereNumber('id');
        Route::put('/{id}/activate', ThirdpartyAdminController::class)->whereNumber('id');
        Route::delete('/{id}', ThirdpartyAdminController::class)->whereNumber('id');
    });
});
