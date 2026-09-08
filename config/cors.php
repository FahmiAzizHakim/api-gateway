<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The frontend is served from its own origin, so the browser will not let it
    | read an API response unless the API says who is allowed to.
    |
    | Origins come from CORS_ALLOWED_ORIGINS in .env, comma separated, e.g.
    |
    |   CORS_ALLOWED_ORIGINS=http://localhost:3000,https://installer.example.com
    |
    | With none set, the common local dev servers are allowed. Never use "*"
    | together with supports_credentials -- browsers reject that pairing, and it
    | would let any site read a logged-in response.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', [
            'http://localhost:3000',    // next / react dev server
            'http://localhost:5173',    // vite dev server
            'http://127.0.0.1:5173',
            'http://localhost:8080',    // vue cli dev server
            'http://localhost:8001',
            'http://127.0.0.1:8001',
            'http://localhost:8002',
            'http://127.0.0.1:8002',
            'http://localhost:8003',
            'http://127.0.0.1:8003',
            'http://127.0.0.1:3000'
        ])))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Read by the frontend when it needs pagination or rate-limit headers.
    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | On, because the guest cart is a cookie.
    |
    | shop-service mints cart_token and ServiceProxy relays it both ways, but a
    | browser will neither send nor store that cookie cross-origin unless this
    | is true and the frontend sets withCredentials. Safe here only because
    | allowed_origins above lists exact origins -- a browser rejects "*"
    | together with credentials, and it would let any site read the response.
    |
    | Note the cookie is SameSite=lax, so the frontend origin and the gateway
    | must share a hostname (ports may differ). CORS_ALLOWED_ORIGINS and
    | APP_URL already agree on one.
    */
    'supports_credentials' => true,

];
