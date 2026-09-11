<?php

namespace App\Http\Controllers\Api;

use App\Services\Gateway\ServiceProxy;
use App\Services\Payment\OrderQrisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The QRIS a visitor scans, and whether it has been paid.
 *
 * Two routes, answered differently:
 *
 *   active()         forwarded. The codes live in thirdparty-service beside
 *                    the Qrisly credentials that registered them, so the
 *                    gateway holds none of this.
 *   paymentStatus()  orchestrated here, because no single service can answer
 *                    it: shop-service knows which order a receipt token is,
 *                    thirdparty-service knows what Qrisly says about its
 *                    payment, and only the gateway reaches both.
 *
 * Neither generates anything. A payment is raised once, when the order is
 * placed (ShopController::checkout); everything here is a question about one
 * that already exists.
 *
 * Public, and safe to be: a QRIS is meant to be looked at, and the receipt
 * token is what keeps one order's payment to whoever holds its link.
 */
class PublicQrisController extends ApiController
{
    protected $proxy;
    protected $orders;

    public function __construct(ServiceProxy $proxy, OrderQrisService $orders)
    {
        $this->proxy  = $proxy;
        $this->orders = $orders;
    }

    /**
     * GET /api/v1/sites/{website}/qris -- forwarded to thirdparty-service.
     *
     * Relayed rather than answered: the codes table moved there with the
     * Qrisly integration, so the gateway holds none of this. The route stays
     * because the frontend talks to one origin, and the path is identical on
     * both sides.
     *
     * `data: null` from the far side is a real answer and not an error: a
     * storefront can offer manual transfer alone, and a page has to be able to
     * say "QRIS is unavailable" rather than render an empty frame.
     */
    public function active(Request $request, $website): Response
    {
        return $this->proxy->forward('thirdparty', $request, "/api/v1/sites/{$website}/qris");
    }

    /**
     * GET /api/v1/sites/{website}/receipt/{token}/payment-status
     *
     * Has this order's QRIS been paid? Asks Qrisly and answers with the
     * payment as it stands afterwards.
     *
     * Qrisly sends no webhook, so asking is the only way a payment ever stops
     * being `unpaid`. This is the receipt page's way to ask: it checks the
     * order's latest payment, addressed by the same token the receipt itself
     * is.
     *
     * Never raises a payment. It does settle one, though: an order whose
     * payment Qrisly confirms is moved to paid in shop-service before this
     * answers, so a page that sees `order.settled` does not have to ask
     * anywhere else. That is the only write, and Qrisly's verdict is the only
     * thing that triggers it.
     *
     * Qrisly is asked only while the order is still waiting for money. Once it
     * is paid the answer comes from what is stored, so a page left polling
     * costs nothing.
     *
     * `checked` says whether Qrisly was really called; `order.settled` is what
     * a receipt acts on, and either going true is a poller's signal to stop.
     *
     * 404 when no payment was ever raised for the order, 422 for an order paid
     * by transfer or a refusal Qrisly explained, 502 when Qrisly could not be
     * reached -- and only the last is worth trying again.
     */
    public function paymentStatus($website, $token): JsonResponse
    {
        $result = $this->orders->checkStatusForOrder((int) $website, (string) $token);

        if (($result['status'] ?? 'failed') !== 'success') {
            return response()->json(
                ['message' => $result['message'] ?? 'The payment status could not be checked'],
                $result['code'] ?? 422
            );
        }

        return response()->json([
            'data'    => $result['data'],
            'checked' => $result['checked'] ?? false,
            // The order's own state, which is what a receipt page acts on:
            // `settled` says stop showing the QR code, `changed` whether this
            // call was the one that settled it.
            'order'   => $result['order'] ?? null,
        ]);
    }

    /*
     * There is deliberately no route here that raises a payment.
     *
     * One used to answer GET .../receipt/{token}/qris by generating whenever
     * the page asked, which made opening a receipt a thing that could call
     * Qrisly. A payment is raised once, where the order is placed
     * (ShopController::checkout), and the receipt carries it under
     * `data.qris_payment` as a plain read.
     */
}
