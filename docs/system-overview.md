# GLS — API gateway and services

Four Laravel apps, four databases. The frontend talks to the gateway; the
gateway talks to the services.

```
vue-vite  ->  API Gateway (this directory)
                  |  JWT
                  +--> website-service      site identity, theming, layout, content
                  +--> shop-service         catalogue, carts, checkout, orders
                  +--> thirdparty-service   Omile TMS, RajaOngkir, Qrisly
```

The three services are API-only: no Blade, no session, no `users` table. Each
one boots from `routes/api.php` alone and renders every failure as JSON
(`app/Exceptions/ApiExceptionHandler`).

## Who owns what

| | tables | public API | admin API |
|---|---|---|---|
| **gateway** (`.`) | users, sessions, menus, access groups | `/api/auth/*`, `/api/files/{path}`, `/api/v1/sites/{id}/receipt/{token}/payment-status`, `/api/v1/*` —> website-service | `/api/admin/{users,access-groups,menus,files}` |
| **website-service** | websites, web_styles, web_sections, styles, banners, contents, content_views, content_comments, abouts, message | `/api/v1/portal`, `/api/v1/sites/{id}/*` | `/api/admin/{website,styles,sections,banners,contents,comments,abouts,messages}` |
| **shop-service** | services, categories, products, product_views, packages, carts, transactions, banks, other_charges, delivery_prices | `/api/v1/sites/{id}/{catalog,cart,checkout,receipt,...}`, `/api/v1/regions/*` | `/api/admin/{services,categories,products,packages,other-charges,banks,delivery-prices,transactions}` |
| **thirdparty-service** | qris, qris_payments, api_logs, plus reference lookups | `/api/v1/logistics/*`, `/api/v1/shipping/*`, `/api/v1/sites/{id}/qris`, `/api/v1/payment/qris{,/{history_id},/reference/{ref}}`, `/api/v1/payment/qris/{history_id\|reference/{ref}}/status` | `/api/admin/shipping/mapping/sync`, `/api/admin/qris/*` |

Every app also keeps the shared reference data — `codes`, the `glb_*` regions,
`rajaongkirmap`, `couriers` — so no lookup needs a cross-service call. That
duplication is deliberate for now and worth revisiting.

`website_id` is the seam. The websites live in website-service; everywhere else
it is a plain column, and the four databases only have to agree on the ids
(`SITE_IDS` / `config/sites.php` in the gateway).

## Identity

The gateway is the only holder of the `users` table, so it is the only place a
password is checked. Its JWT carries what a service needs:

```php
// App\Models\User::getJWTCustomClaims
['website_id' => …, 'email' => …, 'name' => …, 'roles_code' => …]
```

Each service reads those through two helpers in `app/Helpers/helpers.php`:

```php
admin_website_id()   // which website this request may touch
acting_user_email()  // what to stamp on created_by / updated_by
```

**The gateway half is built.** `POST /api/auth/login` checks the password and
mints the token; `/api/auth/{me,refresh,logout}` sit behind `auth:api`. Refresh
and logout blacklist the old token, so it stops working immediately. Login is
rate limited (10/min per IP) and rejects `is_active = 0` accounts with a 403.

**The service half is not.** Each service still needs a `VerifyJwt` middleware
that verifies the token and writes the claims onto the request attribute bag —
the helpers already look there first, so nothing else changes. Until then the
services fall back to request headers:

```
X-Website-Id: 2
X-User-Email: admin@instalasi.com
```

Seeded accounts (password `password123`): `admin@cargo.com` (site 1),
`admin@instalasi.com` (site 2), `admin@evcharging.com` (site 3).

## Forwarding

The frontend talks to one origin. Anything it asks for that the gateway does not
own itself is forwarded to the service that does, by
`App\Services\Gateway\ServiceProxy`, and the service's answer is relayed
untouched — same status, same body. Only an unreachable service is rewritten,
as a 502, so a caller can tell "the request was rejected" from "nobody
answered". The base URLs live in `config/gateway.php`:

```
WEBSITE_SERVICE_URL=http://127.0.0.1:8001
SHOP_SERVICE_URL=http://127.0.0.1:8002
THIRDPARTY_SERVICE_URL=http://127.0.0.1:8003
```

**Public reads are forwarded already** — `GET /api/v1/portal`,
`/api/v1/sites/{id}/*` and the contact form, all to website-service and none of
them needing a token. That is what lets a browser open a site: one CORS
configuration, one host named in `vue-vite/.env`, and services that can be
closed to everything but the gateway.

Because the browser now knows only the gateway, the services point `ASSET_URL`
at it. Their `asset()` calls would otherwise build URLs against their own
`APP_URL` — a port that does not hold the file, since uploads are written
into the gateway's document root (`UPLOAD_PUBLIC_ROOT`).

The catalogue (shop-service) and the admin routes are not forwarded yet; the
admin half waits on `VerifyJwt` in the services.

## Layers

All four apps are built the same way, and a request moves through them in one
direction only:

```
Controller    validates the request, shapes the response
    |         (Form Requests in, ApiController + Resources out)
Service       the business case: rules, transactions, orchestration,
    |         and the calls out to other services
Repository    the queries: find, list, create, update, delete
    |
Model         the table, its relations, its casts and scopes
```

The line that matters is between the middle two. A **Service** decides — whether
a delete is allowed, what a failure should say, which writes have to succeed
together — and a **Repository** only fetches and stores. So `DB::beginTransaction`
lives in a service and never in a repository, and a `where` clause lives in a
repository and never in a service.

A layer talks to the one below it and no further: controllers never touch a
repository or a model's query methods, services never call `Model::where` or
`DB::table`. Naming a model as a type hint is fine — `markAsRead(Message $m)` —
it is querying through one that is not.

Repositories mirror the service namespaces (`App\Repositories\Commerce\
BankRepository` behind `App\Services\Commerce\BankService`) and extend
`App\Repositories\BaseRepository`, which carries `find`, `findForWebsite`,
`existsForWebsite`, `create`, `update`, `delete` and the `forWebsite` scope.
They are plain classes with no interfaces: Laravel autowires them by
constructor injection, so there is no binding table to keep in step.

`forWebsite($id)` is the scope nearly every query starts from, and a null id
means "every website" — how an admin reads across all of them. Where ownership
runs through a relation rather than a column, the repository overrides
`scopeWebsite()` alone: `ProductRepository` does, because a product belongs to a
website through its service.

Two deliberate exceptions: `Website::current()` stays on the model, since it
resolves and caches which site a *request* belongs to and a global helper reads
it before the container exists; and thirdparty-service's `NotificationService`
still queries models directly because it is dead code that does not compile
against this service (see its docblock).

## Conventions

- **Responses.** Services answer `['status', 'message', 'data']`; the base
  `Api\ApiController` turns that into JSON. A rejected write is 422. In
  thirdparty-service a failed upstream is 502, so a caller can tell whose fault
  it was.
- **Uploads are POST, not PUT** — PHP does not parse a multipart body on PUT,
  so any endpoint carrying a file uses POST for update too.
- **Files** land in the gateway's `public/`. The services point
  `UPLOAD_PUBLIC_ROOT` at it and store only the relative path, so one host
  serves every image.
- **The QRIS admin fee is quoted flat, then settled.** Checkout charges a round
  200 because the real cost is not knowable yet: the provider takes 100 per
  payment and then nudges the amount by 1–99 so payments of equal value can be
  told apart. So the QRIS is raised for the order's total *less* 100, and once
  the nudge is known the difference comes back as a negative charge line —
  leaving the order's total equal to the figure being scanned, and the customer
  always paying less than quoted rather than being surcharged for a "unique
  code" they never asked for. shop-service `config/checkout.php` holds the
  arithmetic.
- **A QRIS is generated once, when the order is placed.** `ShopController::checkout`
  raises it through `OrderQrisService::raiseForOrder` as part of placing a
  `TRTQR` order, and nothing afterwards generates another: the receipt carries
  the stored payment under `data.qris_payment` as a plain read, so refreshing
  it never calls Qrisly. A generated payment costs the seller, which is why it
  belongs to creating the order rather than to looking at it.
- **The provider's payment window is fifteen minutes.** Since nothing
  regenerates, a customer who does not scan within it has an expired code and
  the order has to be paid another way. Whatever eventually issues a
  replacement must take the amount from the total with the settlement line
  undone (`OrderQrisService::unsettledTotal`), never from `grandtotal`, or each
  regeneration discounts the same fee again and the seller covers the
  difference.
- **A payment is paid because something asked.** Qrisly sends no webhook, so
  asking is the only way a payment stops being `unpaid`. A receipt page asks
  through the gateway's `GET /api/v1/sites/{id}/receipt/{token}/payment-status`,
  which is throttled because a call can cost an upstream request.
- **The order decides whether to ask, and the order is what gets settled.** The
  check runs only while the transaction is still awaiting money — not while the
  payment row says `unpaid`, since the two can disagree and the order is
  authoritative. When Qrisly confirms, both sides move: thirdparty-service
  stamps `paid_at` on the payment, and shop-service is told to advance the
  transaction to `STSPD` via `POST .../receipt/{token}/qris-paid`. That route
  takes no body — the caller names the order and asserts nothing — so holding a
  receipt token is not a way to declare your own order paid; Qrisly's verdict on
  the far side of the gateway is. Both halves are idempotent, so a polling page
  writes one history row, not one per tick, and `order.settled` is its signal to
  stop.
- **Upstream datetimes are converted, not stored as sent.** Qrisly writes
  `expiry_time` in Jakarta wall clock with no offset while these applications
  store UTC, so `QrislyService::timestamp()` normalises everything coming in.
  Left raw, an expired payment reads as live for another seven hours.
- **Prices are never taken from a request.** `CheckoutService` re-reads every
  line from the catalogue and re-resolves the delivery fare, so confirm and
  place cannot disagree.
- **Carts are cookie-keyed** (`cart_token`), which is why shop-service is the
  one service that loads the cookie middleware.

## Running one

```bash
cd shop-service            # or website-service, thirdparty-service, or .
php artisan migrate
php artisan db:seed
php artisan serve --host=0.0.0.0 --port=8002
```

Ports (`vue-vite/.env`, and the gateway's own `.env`): gateway **8000**,
website-service **8001**, shop-service **8002**, thirdparty-service **8003**.
The frontend runs on **5173** (`cd vue-vite && npm run dev`) and now names only
the gateway — `VITE_GATEWAY_BASE` and `VITE_API_BASE` are both :8000, and
the gateway reaches the services itself. Sign in at `/login`.

Start website-service before opening a site even so: the gateway forwards to it,
and with it down every public page answers 502.

> **`--host=0.0.0.0` is not optional here.** Without it `php artisan serve`
> binds `127.0.0.1` only, so the service answers on this machine and nowhere
> else — a request from the LAN IP, or from another device, is refused at the
> TCP level. `0.0.0.0` means "every interface", which includes loopback, so
> `127.0.0.1:8000` keeps working at the same time. Vite is bound the same way
> by `server.host: true` in `vue-vite/vite.config.ts`.
>
> The URLs in `vue-vite/.env` and every backend's `APP_URL` /
> `CORS_ALLOWED_ORIGINS` name **192.168.1.3**, this machine's LAN IP. If DHCP
> hands out a different address, update those five files and restart — Vite
> reads env only at startup, and Laravel needs `php artisan config:clear` if the
> config has been cached.

> **Use `127.0.0.1`, not `localhost`, for the backend URLs.** `php artisan
> serve` binds IPv4 only, while Windows resolves `localhost` to `::1` (IPv6)
> first — so the browser tries `[::1]:8000`, finds nothing listening, and
> reports the refused connection as a **CORS error**, because a request that
> never completes carries no `Access-Control-Allow-Origin` header. `vue-vite/.env`
> names the IP for exactly this reason. A genuine CORS misconfiguration looks
> different: the request returns a status code, just without the header.

Databases: `gls-gateway`, `gls-website-service`, `gls-shop-service`,
`gls-thirdparty-service`.

`db:seed` splits in two everywhere — slow reference data that only a fresh
install needs, and `SiteStructureSeeder`, which is safe to re-run on a live
database:

```bash
php artisan db:seed --class=SiteStructureSeeder
```

## Not done yet

- `VerifyJwt` middleware in the three services, and the gateway routes that
  forward a verified token to them. The public reads are forwarded already (see
  **Forwarding**); the admin half is what is left, and the gateway's own login
  is done.
- shop-service's public routes — the catalogue, cart and checkout — are not
  forwarded, so `/api/v1/sites/{id}/{services,products,packages}` is a 404 at
  the gateway. Adding them is a controller like `Api\SiteController` naming
  `'shop'` instead of `'website'`; the cart's `cart_token` cookie is the one
  thing that needs thought.
- `XenditService` (thirdparty-service) needs `composer require xendit/xendit-php`.
- `NotificationService` (thirdparty-service) does not compile — the models it
  imports were never part of this codebase. Kept for its intent; needs its
  models and migrations written.
- The landing page is now two calls: website-service for the page, shop-service
  for the catalogue. Pass `?has_packages=1` to website-service so it can decide
  whether to show the packages block.
