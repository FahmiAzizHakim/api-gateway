<?php

use App\Http\Controllers\Api\Admin\GroupMenuController;
use App\Http\Controllers\Api\Admin\ShopAdminController;
use App\Http\Controllers\Api\Admin\ThirdpartyAdminController;
use App\Http\Controllers\Api\Admin\WebsiteAdminController;
use App\Http\Controllers\Api\Admin\MenuController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SiteController;
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
| Behind it, each service holds its own database and trusts the token:
|
|   website-service     site identity, theming, page layout, content
|   shop-service        catalogue, carts, checkout, orders
|   thirdparty-service  Omile TMS and RajaOngkir
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

        /* ---- Orders -> shop-service ---- */

        // Email plus the last digits of a phone, so a guessable pair: rate
        // limited here for the same reason /contact is, and more tightly,
        // because each attempt is a try at someone else's order.
        Route::post('/orders/lookup', [ShopController::class, 'lookupOrder'])
            ->middleware('throttle:10,1');

        // Addressed by its unguessable token, which is what keeps it public.
        Route::get('/receipt/{token}', [ShopController::class, 'receipt']);
        Route::post('/receipt/{token}/attachments', [ShopController::class, 'uploadAttachment']);
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
        Route::get('/{id}', WebsiteAdminController::class);
        Route::post('/{id}', WebsiteAdminController::class);
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
    | Maintenance rather than traffic: re-reads the courier's area list into
    | the mapping table.
    */

    Route::post('/shipping/mapping/sync', ThirdpartyAdminController::class);
});
