<?php

namespace App\Http\Controllers\Api;

use App\Services\Gateway\ServiceProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public storefront, forwarded to shop-service.
 *
 * The counterpart to SiteController: that one carries a site's identity and
 * editorial content from website-service, this one carries what is for sale
 * and what happens to it -- catalogue, basket, checkout, receipts -- from
 * shop-service. A storefront page needs both, and now asks one host for them.
 *
 * No token, and no login behind any of it. A guest shops with a cart cookie
 * and checks out with an address; an order is read back by a token that is
 * unguessable, which is what keeps the receipt routes public without one.
 *
 * The gateway holds none of this data and decides none of it: each action
 * names the shop-service route it stands for, and the answer -- including a
 * 422 from checkout validation, which the form needs field by field -- goes
 * back as it arrived.
 *
 * The seller's half of shop-service (/api/admin/...) is not forwarded here.
 * It has no caller in the frontend yet and belongs behind the JWT, so it gets
 * its own controller once the admin routes are wrapped in 'auth:api'.
 */
class ShopController extends ApiController
{
    protected $proxy;

    public function __construct(ServiceProxy $proxy)
    {
        $this->proxy = $proxy;
    }

    /*
    |--------------------------------------------------------------------------
    | Regions
    |--------------------------------------------------------------------------
    |
    | The checkout address form, filled one level at a time. Not website
    | scoped: the region tables are the same whoever is selling.
    */

    public function provinces(Request $request): Response
    {
        return $this->shop($request, '/regions/provinces');
    }

    public function cities(Request $request, $provinceCode): Response
    {
        return $this->shop($request, "/regions/cities/{$provinceCode}");
    }

    public function districts(Request $request, $cityCode): Response
    {
        return $this->shop($request, "/regions/districts/{$cityCode}");
    }

    public function subdistricts(Request $request, $districtCode): Response
    {
        return $this->shop($request, "/regions/subdistricts/{$districtCode}");
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    /** GET /api/v1/sites/{website}/catalog -- services, products and packages at once. */
    public function catalog(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/catalog");
    }

    public function services(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/services");
    }

    public function products(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/products");
    }

    public function product(Request $request, $website, $id): Response
    {
        return $this->shop($request, "/sites/{$website}/products/{$id}");
    }

    public function packages(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/packages");
    }

    /*
    |--------------------------------------------------------------------------
    | Basket
    |--------------------------------------------------------------------------
    |
    | Identified by the cart_token cookie and nothing else, so these are the
    | routes that depend on ServiceProxy passing Cookie out and Set-Cookie
    | back. Without that a basket cannot survive one request to the next.
    */

    public function cart(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/cart");
    }

    public function saveCart(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/cart");
    }

    public function clearCart(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/cart");
    }

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    /** GET /api/v1/sites/{website}/fare -- delivery cost for the chosen address. */
    public function fare(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/fare");
    }

    /** POST .../checkout/confirm -- price the basket, write nothing. */
    public function confirmCheckout(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/checkout/confirm");
    }

    /** POST .../checkout -- place the order; answers with the receipt token. */
    public function checkout(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/checkout");
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    /** POST .../orders/lookup -- email plus the last digits of a phone. */
    public function lookupOrder(Request $request, $website): Response
    {
        return $this->shop($request, "/sites/{$website}/orders/lookup");
    }

    /** GET .../receipt/{token} -- the order, addressed by its own token. */
    public function receipt(Request $request, $website, $token): Response
    {
        return $this->shop($request, "/sites/{$website}/receipt/{$token}");
    }

    /**
     * POST .../receipt/{token}/attachments -- the customer's payment proof.
     *
     * The one multipart route on the storefront, so the one that needs
     * ServiceProxy to forward a body it has not re-encoded.
     */
    public function uploadAttachment(Request $request, $website, $token): Response
    {
        return $this->shop($request, "/sites/{$website}/receipt/{$token}/attachments");
    }

    /**
     * Forward to shop-service's public API. Paths here are relative to its
     * /api/v1 prefix -- the same prefix these routes carry -- so a route in
     * this app and the route it stands for read as the same URL.
     */
    protected function shop(Request $request, string $path): Response
    {
        return $this->proxy->forward('shop', $request, '/api/v1' . $path);
    }
}
