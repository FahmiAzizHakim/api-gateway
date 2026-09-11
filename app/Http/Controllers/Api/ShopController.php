<?php

namespace App\Http\Controllers\Api;

use App\Services\Gateway\ServiceProxy;
use App\Services\Payment\OrderQrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 * checkout() is the single exception, and only ever adds: a QRIS order needs a
 * payment raised against a code that lives in thirdparty-service, and since
 * services never call each other, the gateway is the only place both halves
 * meet. shop-service's answer still passes through underneath it.
 *
 * The seller's half of shop-service (/api/admin/...) is not forwarded here.
 * It has no caller in the frontend yet and belongs behind the JWT, so it gets
 * its own controller once the admin routes are wrapped in 'auth:api'.
 */
class ShopController extends ApiController
{
    protected $proxy;

    /**
     * Checkout is the one action here that needs more than a forward: a QRIS
     * order is placed by shop-service and paid against a code thirdparty-service
     * holds, and only the gateway reaches both.
     */
    protected $qris;

    public function __construct(ServiceProxy $proxy, OrderQrisService $qris)
    {
        $this->proxy = $proxy;
        $this->qris  = $qris;
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

    /**
     * POST /api/v1/sites/{website}/products/{id}/view -- count one opening of
     * a product page.
     *
     * A public write, like /contact and the comment box: the visitor who
     * triggers it has no token. shop-service checks the id against what the
     * site publishes before it writes anything, and answers with the new
     * total.
     */
    public function productView(Request $request, $website, $id): Response
    {
        return $this->shop($request, "/sites/{$website}/products/{$id}/view");
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

    /**
     * POST .../checkout -- place the order; answers with the receipt token,
     * and for a QRIS order with the QRIS to scan.
     *
     * The one forward on this controller that does not simply relay. shop-service
     * places the order and can do no more: raising the payment needs the site's
     * registered code, which lives in thirdparty-service, and services never
     * call each other -- so the second half happens here, the way it does for
     * the receipt page.
     *
     * A customer who chose QRIS is shown a code by the checkout response
     * itself, rather than being sent to the receipt page to wait for one.
     *
     * Only ever additive: the order is already placed by the time this runs,
     * so a Qrisly that will not answer must not turn a 201 into an error. It
     * leaves `qris` null with the reason beside it, and the page falls back to
     * manual transfer -- or asks again later, since the receipt route raises
     * one on demand.
     */
    public function checkout(Request $request, $website): Response
    {
        $response = $this->shop($request, "/sites/{$website}/checkout");

        // Anything else is shop-service's answer and none of our business: a
        // transfer order has no payment to raise, and a 422 is the checkout
        // form's, field by field.
        if ($request->input('payment_method') !== 'qris' || $response->getStatusCode() !== 201) {
            return $response;
        }

        $body  = json_decode((string) $response->getContent(), true);
        $token = $body['data']['receipt_token'] ?? null;

        if (!is_array($body) || !$token) {
            return $response;
        }

        $qris = $this->qris->raiseForOrder((int) $website, (string) $token);

        if (($qris['status'] ?? 'failed') === 'success') {
            $body['data']['qris'] = $qris['data'];

            /*
             * Raising the payment settles the admin fee, which moves the
             * order's total -- so the figure shop-service answered with a
             * moment ago is already stale. Replaced here rather than left for
             * the page to reconcile against the amount on the QR code.
             */
            if (isset($qris['data']['order']['grandtotal'])) {
                $body['data']['total'] = (float) $qris['data']['order']['grandtotal'];
            }
        } else {
            $body['data']['qris'] = null;
            // What went wrong, for a page that has to explain why it is
            // offering a bank transfer instead.
            $body['data']['qris_error'] = $qris['message'] ?? null;

            Log::warning('gateway: order placed but its QRIS could not be raised', [
                'website_id' => (int) $website,
                'reason'     => $qris['reason'] ?? $qris['message'] ?? null,
            ]);
        }

        $response->setContent(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
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

    /**
     * GET .../receipt/{token} -- the order, addressed by its own token.
     *
     * A QRIS order carries its payment with it, under `data.qris_payment`:
     * `qris_string` is what the page renders as the QR code, and
     * `final_amount` the figure to show beside it. Only the newest is
     * attached -- an order can collect several as Qrisly's fifteen-minute
     * windows close, and only the last one is scannable.
     *
     * Read-only, deliberately, and the only way a placed order's QRIS is ever
     * looked at. This reports the payment that exists; it never raises one, so
     * opening a receipt costs nothing, cannot move the order's total, and
     * cannot spend the seller's Qrisly balance however often it is refreshed.
     * Raising happens once, at checkout, and nowhere else.
     */
    public function receipt(Request $request, $website, $token): Response
    {
        $response = $this->shop($request, "/sites/{$website}/receipt/{$token}");

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $body  = json_decode((string) $response->getContent(), true);
        $order = $body['data']['transaction'] ?? null;

        if (!is_array($order) || ($order['payment_type'] ?? null) !== OrderQrisService::PAYMENT_TYPE_QRIS) {
            // Paid by transfer, so there is no QRIS to attach and no reason to
            // ask thirdparty-service about one.
            return $response;
        }

        // The order's own number is what a payment is stored against.
        $body['data']['qris_payment'] = $this->qris->latestFor((string) ($order['receipt_no'] ?? ''));

        $response->setContent(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
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
