<?php

namespace App\Services\Payment;

use App\Services\Gateway\ServiceClient;
use Illuminate\Support\Facades\Log;

/**
 * The QRIS a customer scans to pay for one order.
 *
 * Two services hold half the answer each, and the gateway is the only one that
 * can reach both -- which is why this orchestration lives here and in neither
 * of them:
 *
 *   shop-service        what the order costs, whether it is a QRIS order, and
 *                       the discount that settles its admin fee afterwards
 *   thirdparty-service  the website's registered code, and a payable QRIS
 *                       raised against it
 *
 * The admin fee is the awkward part, and the reason this is three calls rather
 * than one. Checkout quotes a flat charge because Qrisly's unique nudge does
 * not exist yet; the QRIS therefore has to be raised for less than the order's
 * total, and once the nudge is known the difference goes back to the order as
 * a discount. So: read the order, raise the payment, settle the fee.
 *
 * The receipt token is the whole of the authorisation, as it is for the
 * receipt itself: it is unguessable, and holding it is what permits reading
 * the order. So nothing here needs a login, and none of the three ids a
 * caller would have to know -- website, order, registered code -- is asked
 * for.
 *
 * There are two entry points and they differ in exactly one way that matters:
 * raiseForOrder() generates, and runs once, as part of placing the order.
 * latestFor() reads what was generated, and is what every later look at the
 * order goes through. Nothing a customer does after checkout reaches Qrisly.
 *
 * Statuses that stop a payment being raised are read from the order rather
 * than assumed, so an order already paid can never be given a fresh QR code.
 */
class OrderQrisService
{
    /** codes.code for a QRIS order, as shop-service stores it. */
    public const PAYMENT_TYPE_QRIS = 'TRTQR';

    /**
     * Order statuses where asking for a payment is still the right thing.
     *
     * Awaiting payment, and awaiting confirmation of one -- a customer who
     * uploaded proof and then decided to scan instead is still trying to pay.
     * Anything further on (paid, in delivery, completed, cancelled) is not.
     */
    public const OPEN_STATUSES = ['STSPY', 'STSCP'];

    /**
     * Settled. shop-service's TransactionService::STATUS_PAID, and the status
     * a confirmed QRIS moves an order to.
     *
     * Named here as well as there because the gateway reads it to decide
     * whether to ask Qrisly at all -- the same reason the fee codes are in
     * config/gateway.php. A mismatch would show as a receipt that keeps
     * polling an order nobody is waiting on.
     */
    public const STATUS_PAID = 'STSPD';

    /** What Qrisly calls a settled payment; QrisPayment::STATUS_PAID. */
    public const PAYMENT_STATUS_PAID = 'paid';

    protected $client;

    public function __construct(ServiceClient $client)
    {
        $this->client = $client;
    }

    /**
     * Raise the QRIS for an order that has just been placed.
     *
     * Reads the order, finds the website's active code, and asks
     * thirdparty-service for a payable QRIS of the order's exact total.
     *
     * Called from one place and at one moment: checkout, immediately after
     * shop-service has written the order. That is the whole of when a QRIS is
     * generated -- nothing a customer does afterwards raises another, and
     * opening a receipt a hundred times calls Qrisly not at all. A generated
     * payment costs the seller, so it belongs to placing the order rather than
     * to looking at it.
     *
     * @return array{status: string, message?: string, data?: array}
     */
    public function raiseForOrder(int $websiteId, string $token): array
    {
        /* ---- 1. the order, from shop-service ---- */

        $found = $this->qrisOrder($websiteId, $token);

        if (isset($found['failure'])) {
            return $found['failure'];
        }

        $order = $found['order'];

        /* ---- 2. the payable QRIS, from thirdparty-service ---- */

        // The order's own number is the reference: it is what thirdparty-service
        // matches a payment back to, and what makes a second visit reuse the
        // first visit's code.
        $reference = $order['receipt_no'] ?? $token;
        // The order's total before any earlier settlement -- see
        // unsettledTotal(). Not grandtotal: on a second QRIS for the same
        // order that figure has already had the first payment's discount taken
        // off it, and asking for it again would take the discount twice.
        $amount    = $this->unsettledTotal($order);

        // What the order quoted for admin, which is what the QRIS must be
        // raised for less of. Zero when the order carries no such line -- an
        // order placed before the fee existed, or a site with it switched off
        // -- and then the total is requested as it stands.
        $quoted = $this->quotedAdminCharge($order);

        /*
         * Not awaiting payment, so nothing to raise.
         *
         * An order placed a moment ago always is, so this guards against being
         * called for an order that is not newly placed rather than against
         * anything checkout does. Answering rather than raising is the point:
         * a paid order must never get a fresh QR code.
         */
        if (!in_array($order['status'] ?? '', self::OPEN_STATUSES, true)) {
            return [
                'status'  => 'failed',
                'message' => 'This order is not awaiting a QRIS payment',
                'code'    => 422,
            ];
        }

        if ($amount <= 0) {
            return ['status' => 'failed', 'message' => 'This order has nothing left to pay', 'code' => 422];
        }

        $payment = $this->client->postJson('thirdparty', '/api/v1/payment/qris', [
            // The website, not a code: thirdparty-service holds the codes and
            // resolves the active one itself. A site with none answers 422
            // from there, with a message this method passes on.
            'website_id'    => $websiteId,
            'amount'        => $amount,
            // thirdparty-service subtracts its own knowledge of Qrisly's fee
            // from this and asks for the rest, so the customer pays the goods
            // plus that fee plus the nudge -- never more than they were quoted.
            'admin_charge'  => $quoted,
            'reference'     => $reference,
            // On, so two orders of the same value stay distinguishable when
            // the money arrives.
            'unique_amount' => true,
        ]);

        if (!($payment['success'] ?? false)) {
            // Qrisly's own words reach here through two services -- "insufficient
            // balance" is the seller's problem, not the shopper's -- so it is
            // logged with the order and the caller gets something it can act on.
            Log::warning('gateway: could not raise a QRIS payment', [
                'website_id' => $websiteId,
                'reference'  => $reference,
                'amount'     => $amount,
                'message'    => $payment['message'] ?? null,
            ]);

            return [
                'status'  => 'failed',
                'message' => 'QRIS could not be prepared for this order. Please use manual transfer or contact us.',
                // The upstream reason, for whoever reads the response rather
                // than the page: not shown to a shopper.
                'reason'  => $payment['message'] ?? null,
                'code'    => 502,
            ];
        }

        $raised = $payment['data'] ?? [];

        // Now the nudge is known, so the order can be brought down to what the
        // QRIS actually asks for.
        $adjustment = $this->settleAdminFee($websiteId, $token, $raised, $quoted, $order);

        if ($adjustment === false) {
            // Deliberately fatal. A QRIS asking for 120 against an order that
            // still says 200 is the confusion this whole scheme exists to
            // remove, and it would leave the seller with an order that looks
            // underpaid. Better to say "use manual transfer" than to show a
            // figure that disagrees with the order.
            return [
                'status'  => 'failed',
                'message' => 'QRIS could not be prepared for this order. Please use manual transfer or contact us.',
                'reason'  => 'the admin fee adjustment could not be recorded',
                'code'    => 502,
            ];
        }

        return [
            'status' => 'success',
            'data'   => $this->payload($raised, $order, $adjustment),
        ];
    }

    /**
     * Ask Qrisly whether this order's payment has been paid.
     *
     * The one thing a customer can do after checkout that reaches Qrisly, and
     * it is a question rather than an instruction: it asks about the payment
     * already raised and never raises another. A receipt page waiting for
     * "paid" is what it is for.
     *
     * Addressed by the receipt token like everything else on a receipt, so a
     * guest needs nothing but their link -- and the token is what proves the
     * order is theirs to ask about.
     *
     * The order decides whether to ask at all.
     *
     * An order already settled is not asked about again, whatever the payment
     * row happens to say: the transaction is what the question is really about,
     * and once it is paid there is nothing left to learn and no reason to spend
     * an upstream call on a page that keeps polling. So the order's status is
     * the gate, not `qris_payments.payment_status` -- those can disagree, and
     * when they do it is the order that is authoritative.
     *
     * The latest payment is the one checked; thirdparty-service picks it.
     * `checked` says whether Qrisly was actually called: false means either
     * this order was already paid, or the stored payment was already settled
     * and the row answered. Either way, a poller can stop.
     *
     * A confirmed payment settles the order. thirdparty-service records that
     * the money arrived and shop-service is told to move the transaction to
     * paid -- one call, and only when Qrisly itself said so.
     *
     * @return array{status: string, message?: string, data?: array, checked?: bool, order?: array}
     */
    public function checkStatusForOrder(int $websiteId, string $token): array
    {
        $found = $this->qrisOrder($websiteId, $token);

        if (isset($found['failure'])) {
            return $found['failure'];
        }

        $order     = $found['order'];
        $reference = $order['receipt_no'] ?? '';

        /*
         * Already paid: answer from what is stored and ask Qrisly nothing.
         *
         * The one case that skips the upstream entirely. It is checked against
         * the order rather than the payment because the order is the thing a
         * customer is waiting on -- an order settled by hand, or by a transfer
         * that arrived another way, is done regardless of what Qrisly thinks of
         * a QRIS nobody scanned.
         */
        if (($order['status'] ?? null) === self::STATUS_PAID) {
            return [
                'status'  => 'success',
                'data'    => $this->latestFor($reference),
                'checked' => false,
                'order'   => $this->orderState($order['status'], false),
            ];
        }

        $result = $this->client->get(
            'thirdparty',
            '/api/v1/payment/qris/reference/' . rawurlencode($reference) . '/status'
        );

        if (!($result['success'] ?? false)) {
            /*
             * Passed through with the far side's own status, because the three
             * cases are genuinely different to a page: 404 means no payment was
             * ever raised for this order, 422 that Qrisly refused, and 502 that
             * it could not be reached -- and only the last is worth retrying.
             */
            return [
                'status'  => 'failed',
                'message' => $result['message'] ?? 'The payment status could not be checked',
                'code'    => in_array(($result['status'] ?? 0), [404, 422], true) ? $result['status'] : 502,
            ];
        }

        $payment = $result['data'] ?? null;

        // Qrisly says the money is in, so the order stops waiting for it.
        $settled = ($payment['payment_status'] ?? null) === self::PAYMENT_STATUS_PAID
            ? $this->settleOrder($websiteId, $token, $reference)
            : null;

        return [
            'status' => 'success',
            'data'   => $payment,
            // Read off the whole answer, not 'data': `checked` sits beside the
            // payload rather than inside it.
            'checked' => (bool) ($result['body']['checked'] ?? false),
            'order'   => $settled ?? $this->orderState($order['status'] ?? null, false),
        ];
    }

    /**
     * Tell shop-service the order has been paid.
     *
     * Only ever reached after Qrisly has confirmed the payment, which is what
     * makes it safe: the call carries no amount and no status, so there is
     * nothing for a caller to have influenced -- it names the order and
     * shop-service decides the rest.
     *
     * A failure here is logged and not raised. The payment is real and
     * recorded either way, and refusing to tell the customer their money
     * arrived because a status write failed would be the worse answer; the
     * next poll tries again, and the order is still settleable by hand.
     */
    protected function settleOrder(int $websiteId, string $token, string $reference): array
    {
        $result = $this->client->postJson(
            'shop',
            "/api/v1/sites/{$websiteId}/receipt/" . rawurlencode($token) . '/qris-paid',
            []
        );

        if (!($result['success'] ?? false)) {
            Log::error('gateway: QRIS confirmed paid but the order could not be settled', [
                'website_id' => $websiteId,
                'reference'  => $reference,
                'message'    => $result['message'] ?? null,
            ]);

            return $this->orderState(null, false);
        }

        return $this->orderState(
            $result['data']['status'] ?? self::STATUS_PAID,
            (bool) ($result['data']['changed'] ?? false)
        );
    }

    /**
     * The order's side of a status check, for a page that has to decide what
     * to show.
     *
     * `settled` is the question a receipt actually asks -- may I stop showing
     * a QR code -- and `changed` says whether this particular call was the one
     * that moved it, which is what distinguishes "just paid" from "was already
     * paid" without the page having to remember.
     */
    protected function orderState(?string $status, bool $changed): array
    {
        return [
            'status'  => $status,
            'settled' => $status === self::STATUS_PAID,
            'changed' => $changed,
        ];
    }

    /**
     * The QRIS order a receipt token addresses.
     *
     * Shared by the two entry points that need an order before they can act,
     * so a bad token, a missing order and a transfer order are refused the
     * same way and in the same words whichever was called.
     *
     * Answers ['order' => [...]] or ['failure' => [...]], the second being the
     * caller's return value as it stands.
     */
    protected function qrisOrder(int $websiteId, string $token): array
    {
        $receipt = $this->client->get('shop', "/api/v1/sites/{$websiteId}/receipt/" . rawurlencode($token));

        if (!($receipt['success'] ?? false)) {
            // A bad token is shop-service's 404, and it stays a 404: the
            // caller asked for an order that is not theirs or not there.
            return ['failure' => [
                'status'  => 'failed',
                'message' => $receipt['message'] ?? 'Order not found',
                'code'    => ($receipt['status'] ?? 0) === 404 ? 404 : 502,
            ]];
        }

        $order = $receipt['data']['transaction'] ?? null;

        if (!$order) {
            return ['failure' => ['status' => 'failed', 'message' => 'Order not found', 'code' => 404]];
        }

        if (($order['payment_type'] ?? null) !== self::PAYMENT_TYPE_QRIS) {
            // Not an error the customer caused: this order is being paid by
            // transfer, so it has no QRIS to raise or ask about.
            return ['failure' => [
                'status'  => 'failed',
                'message' => 'This order is not being paid by QRIS',
                'code'    => 422,
            ]];
        }

        return ['order' => $order];
    }

    /**
     * The latest QRIS payment raised for an order, or null when there is none.
     *
     * A read, and only a read. raiseForOrder() above calls Qrisly and moves
     * the order's total; this asks thirdparty-service what is already stored
     * and does neither, which is what makes it safe to hang off the receipt --
     * a page that shows an order must not raise a payment as a side effect of
     * being looked at.
     *
     * One payment, not the list: an order can accumulate several as Qrisly's
     * fifteen-minute windows close, but only the newest is the one to scan.
     * thirdparty-service orders them newest first.
     */
    public function latestFor(string $reference): ?array
    {
        $result = $this->client->get(
            'thirdparty',
            '/api/v1/payment/qris/reference/' . rawurlencode($reference)
        );

        if (!($result['success'] ?? false)) {
            // Not an error worth failing a receipt over: the order still reads
            // fine without a payment attached, and a page that gets null can
            // offer manual transfer rather than showing nothing.
            Log::warning('gateway: could not read the QRIS payments for an order', [
                'reference' => $reference,
                'message'   => $result['message'] ?? null,
            ]);

            return null;
        }

        return ($result['data'] ?? [])[0] ?? null;
    }

    /**
     * The admin charge the order quoted, from its charge lines.
     *
     * Identified by code (config/gateway.php), which is the one thing the
     * gateway has to know about the fee scheme: shop-service decides what to
     * quote and thirdparty-service knows what Qrisly takes, but somebody has
     * to read the quote off the order, and only the gateway sees both sides.
     */
    protected function quotedAdminCharge(array $order): float
    {
        $code = (string) config('gateway.qris_fee_code');

        foreach ($order['charge_items'] ?? [] as $line) {
            if (($line['code'] ?? null) === $code) {
                return (float) ($line['amount'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * The order's total with any earlier settlement undone.
     *
     * The figure a QRIS must be raised against, and not the same as
     * grandtotal once a payment has been raised before. Qrisly's payment
     * window is fifteen minutes, so a customer who leaves their receipt open
     * and comes back needs a second payment -- and by then the first one's
     * discount is already off the order. Raising the second against that
     * reduced total would discount the same fee twice, and go on doing it: the
     * customer would be asked for less than the goods each time, and the
     * seller would quietly cover the difference.
     *
     * So the settlement line -- negative, hence subtracted to add it back --
     * is undone first, and every payment for an order is raised against the
     * same base. shop-service does the mirror of this when it recalculates the
     * discount (sumChargesExcept), which is what makes the two agree.
     */
    protected function unsettledTotal(array $order): float
    {
        $total = (float) ($order['grandtotal'] ?? 0);
        $code  = (string) config('gateway.qris_discount_code');

        foreach ($order['charge_items'] ?? [] as $line) {
            if (($line['code'] ?? null) === $code) {
                $total -= (float) ($line['amount'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Record the discount that settles the admin fee, and answer with the
     * order's money afterwards.
     *
     * Skipped only when there is nothing to settle at all -- an order with no
     * admin quote on it, or a payment with no amount. Otherwise it is always
     * called, including for a reused payment: shop-service recalculates the
     * line from the order's own charges rather than adjusting what is there,
     * so calling it again with the same figure changes nothing, and calling it
     * with a new payment's figure corrects the order. Not calling it is what
     * would leave a stale total behind.
     *
     * Answers false when the call was needed and failed, which the caller
     * treats as fatal.
     *
     * @return array|false
     */
    protected function settleAdminFee(int $websiteId, string $token, array $raised, float $quoted, array $order)
    {
        $paid = (float) ($raised['final_amount'] ?? 0);

        if ($quoted <= 0 || $paid <= 0) {
            return [];
        }

        $result = $this->client->postJson(
            'shop',
            "/api/v1/sites/{$websiteId}/receipt/" . rawurlencode($token) . '/qris-adjustment',
            ['paid_amount' => $paid]
        );

        if (!($result['success'] ?? false)) {
            Log::error('gateway: QRIS raised but its admin-fee discount could not be recorded', [
                'website_id' => $websiteId,
                'reference'  => $order['receipt_no'] ?? null,
                'paid'       => $paid,
                'message'    => $result['message'] ?? null,
            ]);

            return false;
        }

        return $result['data'] ?? [];
    }

    /**
     * What the receipt page reads.
     *
     * The payment as thirdparty-service described it -- the code it belongs to
     * included, since that service now holds both -- plus the order it is for,
     * which is the half only the gateway saw.
     */
    protected function payload(array $payment, array $order, array $adjustment = []): array
    {
        return [
            // The payload to render as a QR code.
            'qris_string' => $payment['qris_string'] ?? null,
            // What the customer must actually transfer, which is not the
            // order's total: unique_amount nudges it so payments can be told
            // apart. The page must show this figure, not grandtotal.
            'final_amount'    => isset($payment['final_amount']) ? (float) $payment['final_amount'] : null,
            'original_amount' => isset($payment['original_amount']) ? (float) $payment['original_amount'] : null,
            'payment_status'  => $payment['payment_status'] ?? null,
            'expiry_time'     => $payment['expiry_time'] ?? null,
            'payable'         => (bool) ($payment['payable'] ?? false),
            // The handle for asking later whether this was paid.
            'history_id'      => isset($payment['history_id']) ? (int) $payment['history_id'] : null,

            // The registered code behind it, for the page's heading and its
            // static fallback image. Answered by thirdparty-service, which
            // holds the codes.
            'qris' => $payment['qris'] ?? null,

            // Echoed so a page can check it is showing the order it thinks.
            //
            // grandtotal is the settled figure where the fee was adjusted --
            // which by then equals final_amount, so the order's total and the
            // amount to scan agree, and the page has no two numbers to
            // reconcile.
            'order' => [
                'receipt_no' => $order['receipt_no'] ?? null,
                'grandtotal' => isset($adjustment['grandtotal'])
                    ? (float) $adjustment['grandtotal']
                    : (isset($order['grandtotal']) ? (float) $order['grandtotal'] : null),
                'status'     => $order['status'] ?? null,
            ],

            // What came off the quoted admin charge, for a page that wants to
            // say so rather than leave a customer wondering why the total
            // moved. Zero or absent when nothing was settled.
            'admin_discount' => isset($adjustment['discount']) ? (float) $adjustment['discount'] : null,
        ];
    }
}
