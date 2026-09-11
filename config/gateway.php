<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the services answer
    |--------------------------------------------------------------------------
    |
    | The frontend talks to one host: this one. Everything it asks for that the
    | gateway does not own itself is forwarded to the service that does, and the
    | service's answer is handed back untouched.
    |
    | Origins only -- no /api suffix. The path a request is forwarded to is
    | written at the call site, so a route here and the route it forwards to
    | stay readable side by side.
    |
    | These are private addresses: nothing but the gateway should be able to
    | reach them once this is deployed. Locally they are the three
    | `php artisan serve` ports.
    |
    */

    'services' => [
        'website'    => env('WEBSITE_SERVICE_URL', 'http://127.0.0.1:8001'),
        'shop'       => env('SHOP_SERVICE_URL', 'http://127.0.0.1:8002'),
        'thirdparty' => env('THIRDPARTY_SERVICE_URL', 'http://127.0.0.1:8003'),
    ],

    /*
    | Seconds to wait on a service before giving up and answering 502. Short on
    | purpose: a browser waiting on the gateway is waiting on a page, and a
    | service that has not answered in ten seconds is not about to.
    */

    'timeout' => (int) env('GATEWAY_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Is everything answering?
    |--------------------------------------------------------------------------
    |
    | What GET /api/status pings, and how hard it tries.
    |
    | `path` is Laravel's own health route, which every service has for the
    | same reason this one does -- registered in bootstrap/app.php, outside the
    | api group, so it answers without the gateway token and without touching
    | a database. It is asked for nothing but its status line.
    |
    | `timeout` is deliberately shorter than the forwarding one above: a status
    | page asks about three services at once and is answering a question about
    | whether they are there at all, so waiting ten seconds on a host that is
    | not going to answer only makes the page look broken too.
    |
    | `ttl` is how long an answer is reused. A dashboard polling every second
    | must not turn into three outbound requests a second, and a service does
    | not come back up in less time than this.
    |
    */

    'health' => [
        'path'    => env('GATEWAY_HEALTH_PATH', '/up'),
        'timeout' => (int) env('GATEWAY_HEALTH_TIMEOUT', 3),
        'ttl'     => (int) env('GATEWAY_HEALTH_TTL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | The shared secret that says a request came from here
    |--------------------------------------------------------------------------
    |
    | Sent as X-Gateway-Token on every forward, and checked by every service.
    |
    | It answers a different question from the JWT. The JWT says which user is
    | calling, and most of the storefront has no user at all -- the catalogue,
    | the cart and the region lists are read by visitors who have never signed
    | in, so there is no token on those requests to check. This is what those
    | requests are checked against instead: not who, but what.
    |
    | Same value in all four applications. Empty is a deployment mistake, and
    | one the services treat as fatal rather than waving through -- see the
    | VerifyGatewayToken middleware in each of them.
    */

    'token' => env('GATEWAY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | The QRIS admin charge line
    |--------------------------------------------------------------------------
    |
    | Which of an order's charge lines is the QRIS admin fee.
    |
    | The gateway needs it for one arithmetic step it cannot delegate: a QRIS
    | has to be raised for less than the order's total, by however much the
    | quote exceeds what Qrisly itself takes, and the quote is a line on the
    | order. Reading it by code is the only part of the fee scheme the gateway
    | knows about -- shop-service decides what to quote, thirdparty-service
    | knows what Qrisly takes, and this names the line that carries the first.
    |
    | Must match CHECKOUT_QRIS_FEE_CODE in shop-service. They are the same
    | string in two applications because the row it names is written by one and
    | read by the other; a mismatch shows up as a QRIS raised for the order's
    | full total, which the customer would then be asked to overpay.
    |
    */

    'qris_fee_code' => env('QRIS_FEE_CODE', 'QRISFEE'),

    /*
    | The charge line that settles that fee -- shop-service's
    | CHECKOUT_QRIS_DISCOUNT_CODE, and negative.
    |
    | Read for one reason: when a QRIS is raised a second time for an order
    | that already has one -- the first expired, and Qrisly's window is fifteen
    | minutes -- the order's grandtotal has already been brought down by this
    | line. Asking for that reduced figure again would compound the discount,
    | so the line is added back before the next payment is raised.
    | OrderQrisService::unsettledTotal() is where that happens.
    */
    'qris_discount_code' => env('QRIS_DISCOUNT_CODE', 'QRISDISC'),

];
