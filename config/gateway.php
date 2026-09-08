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

];
