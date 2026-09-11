# API Gateway

The single host the frontend talks to. It owns the things the services
deliberately do not — accounts, sessions, the JWT everything else runs on, and
the public document root that serves every uploaded file — and forwards
everything else to the service that owns it.

```
vue-vite (browser)
      |
      |  one origin, one CORS config, one bearer token
      v
API Gateway  :8000   <- this directory
      |  users · access groups · admin menus · JWT · files
      |
      |  X-Gateway-Token + Authorization: Bearer <jwt>
      +--> website-service      :8001   site identity, theming, layout, content
      +--> shop-service         :8002   catalogue, carts, checkout, orders
      +--> thirdparty-service   :8003   Omile TMS, RajaOngkir, Qrisly
```

The three services and the frontend currently live in subdirectories of this
repository and will move out to their own repositories later. Nothing in the
gateway depends on that layout: it reaches the services over HTTP at the URLs
in `.env`, so moving them only changes those three values. For how the four
applications divide the domain between them, see
[docs/system-overview.md](docs/system-overview.md).

## What it owns, and what it only relays

| | held here | answered elsewhere |
|---|---|---|
| **Data** | `users`, `sessions`, `users_menugroup(detail)`, `menus`, plus the shared reference tables (`codes`, `glb_*` regions, `rajaongkirmap`, `couriers`) | websites, catalogue, orders, courier lookups, QRIS codes and payments |
| **Auth** | the only place a password is checked; the only place a token is checked against the blacklist | services verify the same token's *signature* and read its claims |
| **Files** | `public/uploads`, `public/webassets` — one document root for every site asset and upload | services store only the relative path |
| **Routes** | `/api/auth/*`, `/api/files/*`, `/api/admin/{menus,files,users,access-groups}` | everything under `/api/v1/*` and the rest of `/api/admin/*` |

`website_id` is the seam between the four applications. It lives on `users`
here, travels to the services inside the token, and is what every service
scopes its rows by — which is why none of them needs a `users` table.

## Tech

| | |
|---|---|
| PHP | 8.2+ |
| Framework | Laravel 12 (API-only: no Blade, no web routes, no session-based auth) |
| Auth | `tymon/jwt-auth` ^2.2 — bearer tokens, blacklist enabled |
| Database | MySQL (`gls-gateway`); SQLite in-memory under test |
| HTTP client | Laravel's `Http` facade (Guzzle), wrapped by `App\Services\Gateway\ServiceProxy` |
| Errors | every failure rendered as JSON by `App\Exceptions\ApiExceptionHandler` |
| Tooling | Pint (style), PHPUnit 11 (tests), Pail (log tail), Sail, Collision |

[bootstrap/app.php](bootstrap/app.php) registers `routes/api.php` and
`routes/console.php` and nothing else, so there is no HTML surface: a browser
hitting an unknown path gets `{"message":"Url not found"}` with a 404, not an
error page.

## Quick start

```bash
composer install
cp .env.example .env          # then fill in the values below
php artisan key:generate
php artisan jwt:secret        # writes JWT_SECRET
php artisan migrate
php artisan db:seed
php artisan serve --host=0.0.0.0 --port=8000
```

`composer setup` does the install/key/migrate steps in one go. `composer dev`
runs the server, the queue worker and a live log tail together.

Create the database first (`gls-gateway`), and give `GATEWAY_TOKEN` the **same
value** in the gateway and in all three services — a mismatch makes every
forward fail closed with a 403 from the far side.

Seeded accounts, password `password123`:

| email | website_id |
|---|---|
| `admin@cargo.com` | 1 |
| `admin@instalasi.com` | 2 |
| `admin@evcharging.com` | 3 |

Check it is up:

```bash
curl http://127.0.0.1:8000/up                    # health, no auth

curl -X POST http://127.0.0.1:8000/api/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@instalasi.com","password":"password123"}'
```

The public storefront routes forward to website-service and shop-service, so
start those two as well or those paths answer 502.

> **`--host=0.0.0.0` matters.** Without it `php artisan serve` binds
> `127.0.0.1` only, and a request from the LAN or another device is refused at
> the TCP level. `0.0.0.0` covers every interface, loopback included.
>
> **Use `127.0.0.1`, not `localhost`, in the service URLs.** `artisan serve`
> binds IPv4 only while Windows resolves `localhost` to `::1` first, so the
> connection is refused — and a refused request carries no CORS header, which
> is why this shows up in a browser as a CORS error rather than a connection
> error.

### Which .env file is actually read

`.env` sets `APP_ENV=local`, `php artisan serve` passes `APP_ENV` through to the
server process, and Laravel then prefers `.env.local` over `.env`. So a value
set only in `.env` can be invisible to served requests. The repository keeps
`.env.example`, `.env.local`, `.env.dev` and `.env.prod`; when in doubt, run
`php artisan config:clear` and read the value back:

```bash
php artisan tinker --execute="echo config('gateway.token');"
```

## Environment

| variable | what it does |
|---|---|
| `APP_URL` | the origin the gateway serves on; `asset()` builds file URLs from it |
| `DB_*` | MySQL connection — database `gls-gateway` |
| `JWT_SECRET` | signs the token. **The services must hold the same value** or they cannot verify a forwarded token |
| `JWT_TTL` | access token lifetime in minutes (default 60) |
| `JWT_REFRESH_TTL` | how long a token may still be refreshed, in minutes (default 20160 = 14 days) |
| `JWT_BLACKLIST_GRACE_PERIOD` | seconds a refreshed token keeps working, so requests already in flight do not fail (30 here) |
| `GATEWAY_TOKEN` | shared secret sent as `X-Gateway-Token` on every forward; identical in all four apps |
| `WEBSITE_SERVICE_URL` | origin of website-service — origin only, no `/api` suffix |
| `SHOP_SERVICE_URL` | origin of shop-service |
| `THIRDPARTY_SERVICE_URL` | origin of thirdparty-service |
| `GATEWAY_TIMEOUT` | seconds to wait on a service before answering 502 (10) |
| `GATEWAY_HEALTH_PATH` | what `GET /api/status` pings on each service (`/up`) |
| `GATEWAY_HEALTH_TIMEOUT` | seconds to wait on that ping before calling a service down (3) |
| `GATEWAY_HEALTH_TTL` | seconds a status answer is reused, so polling costs nothing (10). Also how stale the sidebar's view of a service can be |
| `CORS_ALLOWED_ORIGINS` | comma-separated frontend origins. Never `*` — `supports_credentials` is on for the cart cookie, and browsers reject that pairing |
| `SITE_IDS` | the website ids this installation serves (`1,2,3`) |
| `UPLOAD_PUBLIC_ROOT` | absolute path to the served document root uploads are written into; set it wherever `DOCUMENT_ROOT` is empty (CLI, queue) or the docroot is not Laravel's `public/` |

The config files these feed are worth reading directly:
[config/gateway.php](config/gateway.php), [config/cors.php](config/cors.php),
[config/sites.php](config/sites.php), [config/auth.php](config/auth.php).

## Authentication

The gateway holds the only `users` table, so it is the only place a password is
checked. `POST /api/auth/login` mints a token whose custom claims are what the
services read ([app/Models/User.php:54-62](app/Models/User.php#L54-L62)):

```php
['website_id' => …, 'email' => …, 'name' => …, 'roles_code' => …]
```

Everything after login carries `Authorization: Bearer <token>`. There is no
session and no CSRF to think about, and the frontend may live on any allowed
origin.

- **`auth:api` is the JWT guard.** It verifies the signature, checks the token
  against the blacklist — which only this app can do, since the services keep
  their own cache — and resolves the `User` the claims were signed from. This is
  the one place a token is checked against stored state.
- **Refresh and logout blacklist the old token**, so it stops working
  immediately rather than lingering until its TTL runs out.
- **Login is rate limited** to 10 attempts a minute per IP, answers 401 with the
  same message for an unknown email and a wrong password (so it cannot be used
  to enumerate accounts), and answers 403 for an account with `is_active = 0`.
- **Scope never comes from a request.** `admin_website_id()` and
  `acting_user_email()` ([app/Helpers/helpers.php](app/Helpers/helpers.php))
  read the authenticated user first; the `X-Website-Id` / `X-User-Email`
  fallback exists only so endpoints stay callable from curl while the services'
  own JWT verification is being finished.

Token failures come back distinguishable — `Token expired`, `Token invalid`,
`Token not provided`, all 401 — so a client can tell "refresh now" from "sign in
again".

## Forwarding

[ServiceProxy](app/Services/Gateway/ServiceProxy.php) is the whole of what makes
this a gateway. It sends one request to one service and hands the answer back
**unchanged** — same status, same body, same content type. The services already
answer in the shape the frontend expects (`{status, message, data}`), so
re-encoding here would only add a second place for the shape to drift.

```
                      stripped from the caller, then asserted by the gateway
browser ─────────▶ [ auth:api ] ─────▶ ServiceProxy ─────▶ service
  Authorization        verified          X-Gateway-Token       verifies the
  Cookie: cart_token   + blacklist       X-Website-Id          signature, reads
                       checked           X-User-Email          the claims, and
                                         X-Forwarded-For       checks the
                                         X-Forwarded-Host      gateway token
                                         X-Forwarded-Proto
```

- **Forwarded from the caller:** `Accept`, `Accept-Language`, `Authorization`,
  `Cookie`. Everything else describes the browser's connection to the gateway,
  not the gateway's to the service.
- **Asserted by the gateway, never relayed:** `X-Gateway-Token`, `X-Website-Id`,
  `X-User-Email`. They are stripped from whatever arrived and rewritten from the
  verified user, so a caller cannot name the website it wants to act on.
- **`Set-Cookie` is relayed back verbatim.** The guest cart is identified by
  nothing but shop-service's `cart_token` cookie, so it has to survive both
  directions — which is also why `supports_credentials` is on in
  `config/cors.php` and the frontend must send `withCredentials`.
- **Multipart stays multipart.** A request carrying a file is rebuilt as
  streamed parts rather than re-encoded as JSON, which is what makes upload
  endpoints work through the gateway at all. Field names are flattened back to
  the bracket form (`items[0][id]`) the service's validator expects to read.
- **Only "unreachable" is rewritten.** A 404 or 422 from a service is its own
  answer and passes through; a connection failure or timeout becomes a 502, so a
  caller can tell "rejected" from "nobody answered". A missing base URL is a 500
  — that one is a deployment mistake, not a caller's.

## Files

The gateway is the public origin for every file. A logo, a product photo and a
payment receipt are all served to the same browser from the same host, so one
document root holds them and one place decides what may be read.

- Services write into that root by pointing `UPLOAD_PUBLIC_ROOT` at it and store
  **only the relative path** (`uploads/product/foo-20260908-x1y2z3.jpg`).
- They also point `ASSET_URL` at the gateway, so their `asset()` calls build
  URLs against the host that actually holds the file.
- Reads are public — these are images a visitor's browser renders. Only
  `uploads/` and `webassets/` are reachable, and the path is resolved with
  `realpath()` **before** it is checked, so no amount of `../` escapes them.
  Anything outside answers 404, so a probe cannot tell blocked from missing.
- Files are served with a one-year cache: each upload gets a generated filename,
  so a replacement gets a new URL rather than needing a purge.

## The frontend, and its metadata

The gateway also serves the built frontend, and this is the one part of it that
answers HTML rather than JSON. It exists for a single reason: vue-vite renders
in the browser, so the document it ships is an empty `<div>`, and every title,
description and share card is written by JavaScript once the API has answered.
Google runs that JavaScript. **No social scraper does** — WhatsApp, Facebook,
LinkedIn and Slack read the bytes as sent — so a shared article arrived as a
bare link with no title, no excerpt and no image.

So three routes, in `routes/web.php`:

| Route | What it answers |
| --- | --- |
| `GET /sitemap.xml` | Every URL worth indexing: the portal, each site's landing page, each article, with `<lastmod>` from the article's `updated_at`. A crawler fetches this before it runs anything, so the frontend cannot produce it. |
| `GET /assets/{path}` | The build's fingerprinted files, for a deployment that has not copied `dist/` into the public root. Where it has, the web server answers these and PHP never starts. |
| fallback | `index.html`, with an article's `<title>`, description, canonical, Open Graph, Twitter card and JSON-LD already in the `<head>` — see `SpaController` and `SeoTags`. |

A few things worth knowing:

- The injected tags carry `data-page-meta`, which is exactly what vue-vite's
  `usePageMeta()` clears before writing its own. The two sides write the same
  fields on purpose: the scraper reads the server's copy, the app replaces it
  on boot, and neither ends up with two of every tag.
- Only `/{slug}/content/{id}` is enriched today, because that is the page that
  gets shared and searched for. Every other URL is served the same
  `index.html` untouched, so adding one is a case in `SpaController::metaFor()`.
- A missing article answers **404** with `noindex`, rather than 200 with a
  sentence — a soft 404 is indexed as a real but empty page.
- The article, the site and the portal are read through `ServiceClient` and
  cached for `SEO_CACHE_TTL` (default 300s). A crawl is otherwise a small flood
  of identical reads aimed at website-service.
- Nothing here may break the page: an unreachable service or a build that is
  not in place falls back to serving the file, or to plain text saying so.

Configuration is `config/seo.php`:

| Variable | What it is |
| --- | --- |
| `SPA_ROOT` | Where the build lives. Unset, the first of `public/` then `vue-vite/dist/` that holds an `index.html` wins — so a deployment that copies `dist/*` into the public root needs no configuration, and a local checkout that has run `npm run build` is served straight out of `dist`. |
| `SEO_BASE_URL` | The origin canonical URLs are built on. Empty means "the host this request arrived on", which is right for one deployment behind one name and wrong the moment the same app answers on several. Set it once the site has a domain. |
| `SEO_SITEMAP_SLUGS` | The slugs the frontend serves (`installer,ev`). Must match `CATALOG_SLUGS` in `vue-vite/src/shared/config/sites.ts`. Which slug is which website id is not configured — the portal is asked. |
| `SEO_CACHE_TTL` | Seconds an article, a website and the portal are held. |

`public/robots.txt` still needs its `Sitemap:` line: the directive requires an
absolute URL and the file is static, so it cannot be written until the domain
is known.

## API reference

Conventions:

- Success bodies are `{"message": …, "data": …}`; list endpoints answer
  `{"data": [...]}`.
- A rejected write is **422** — `{"message":"Validation failed","errors":{…}}`
  from a Form Request, or the service's own message when a rule fails.
- Everything else follows `ApiExceptionHandler`: 401 unauthorized/token, 403
  forbidden, 404 `Url not found`, 405 method not allowed, 500 fallback with no
  internals leaked.
- `GET /up` is this app's own health check; `GET /api/status` is the whole
  installation's — see below.

### Status — `/api/status` (no token)

| method | path | auth | notes |
|---|---|---|---|
| GET | `/api/status` | — | are the services answering? 30/min per IP |

`/up` says this app is running. It says nothing about the three services behind
it, and from outside there is no way to ask: the frontend knows one origin, so a
service being down arrives as a 502 on whichever call happened to need it —
without saying which service, or whether the rest are fine. This asks all three
at once, concurrently, and answers plainly.

**200 while everything is up, 503 as soon as anything is not**, with the same
body either way, so a monitor can watch the status code alone.

```json
{
  "data": {
    "status": "degraded",
    "gateway": {"name": "Company Profile", "status": "up", "environment": "local"},
    "services": [
      {"name": "website",    "status": "up",   "http_status": 200,  "latency_ms": 12,   "message": null},
      {"name": "shop",       "status": "down", "http_status": null, "latency_ms": 3001, "message": "Unreachable"},
      {"name": "thirdparty", "status": "up",   "http_status": 200,  "latency_ms": 9,    "message": null}
    ],
    "checked_at": "2026-09-11T09:20:15+00:00"
  }
}
```

| state | means |
|---|---|
| `up` | answered `GATEWAY_HEALTH_PATH` with a 2xx |
| `degraded` | answered, but not with a 2xx — the host is there, the app is not well |
| `down` | nothing answered before `GATEWAY_HEALTH_TIMEOUT` ran out |
| `unconfigured` | no base URL in `config/gateway.php`, so nothing was asked |

The overall `status` is `up` only when every service is, `down` when not one of
them answered, `degraded` for anything in between.

Reachability, not correctness: the ping goes to each service's own `/up`, which
is registered outside its api group, so it needs neither `X-Gateway-Token` nor a
database. A service whose database is down, or whose gateway token does not
match this one, still reads as `up` here — those failures show up on the routes
that use them, and they are fixed differently.

Public on purpose: it exposes names, states and timings, never a service URL,
and a status page that needs a login is no use during an outage that includes
the login.

### Auth — `/api/auth`

| method | path | auth | notes |
|---|---|---|---|
| POST | `/login` | — | `{email, password}` → token + user. 10/min per IP |
| GET | `/me` | bearer | the current token's user; called on reload to restore a session |
| POST | `/refresh` | bearer | fresh token, old one blacklisted |
| POST | `/logout` | bearer | blacklists the token |

```json
{
  "message": "Signed in",
  "data": {
    "access_token": "eyJ0…",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
      "id": 2, "name": "Admin Instalasi", "email": "admin@instalasi.com",
      "website_id": 2, "roles_code": "superadmin-instalasi", "is_active": true
    }
  }
}
```

`expires_in` is seconds, which is what a client needs to time a refresh.

### Public storefront — `/api/v1` (no token)

This is what a visitor's browser reads before anyone signs in. The URLs mirror
the `v1` group in each service's own `routes/api.php` one for one, so the two
files can be read side by side; which service answers is visible in the
controller and nowhere else.

| path | forwarded to |
|---|---|
| `GET /portal`, `GET /sites` | website-service |
| `GET /sites/{id}` · `/landing` · `/styles` · `/sections` · `/banners` · `/abouts` · `/clients` · `/contents` · `/contents/{id}` | website-service |
| `POST /sites/{id}/contact` | website-service — the one public write, 20/min per IP |
| `GET /sites/{id}/catalog` · `/services` · `/products` · `/products/{id}` · `/packages` | shop-service |
| `GET · POST · DELETE /sites/{id}/cart` | shop-service — keyed by the `cart_token` cookie, no login behind it |
| `GET /sites/{id}/fare` · `POST /sites/{id}/checkout/confirm` · `POST /sites/{id}/checkout` | shop-service |
| `POST /sites/{id}/orders/lookup` | shop-service — email plus phone digits is a guessable pair, so 10/min per IP |
| `GET /sites/{id}/receipt/{token}` · `POST /sites/{id}/receipt/{token}/attachments` | shop-service — the token is unguessable, which is what keeps it public |
| `GET /regions/provinces` · `/cities/{code}` · `/districts/{code}` · `/subdistricts/{code}` | shop-service — not website scoped |
| `GET /sites/{id}/qris` | thirdparty-service — the site's active QRIS code (`data: null` when it has none) |
| `GET /sites/{id}/receipt/{token}/qris` | **the gateway itself** — the payable QRIS for one order: the string to render, the exact `final_amount` to transfer, and the code behind it. The one call no single service can answer: shop-service has the order's total, thirdparty-service turns it into a payment, and only the gateway reaches both. Raises a payment on the first visit and reuses it afterwards — and once the provider's fifteen-minute window closes, raises another against the same base, so the fee is never discounted twice |

Three calls render a storefront: `/portal`, `/sites/{id}/landing` and
`/sites/{id}/catalog`. A receipt paid by QRIS takes one more — `/sites/{id}/qris`
— because the order and the code come from different applications. Pass `?has_packages=1` to website-service wherever the
packages block's visibility matters — query strings are carried across as they
stand.

#### A landing section says which service it needs

Every section in `/landing` and `/sections` carries a **`service`**, the same
vocabulary as `menus.service` above: `website`, `shop`, `thirdparty`, or `null`
for a block that fetches nothing (`why-us` is markup). Products, Packages, Toko
and Pesanan Anda are `shop` — their contents come from the second call, to
shop-service — so with shop-service down those blocks would render as empty
frames on a page that is otherwise fine.

The storefront prunes them: read `GET /api/status` (public, cached, cheap) and
skip the sections whose service is not `up`. The column lives with the section
rows in website-service, but that service cannot act on it — services never
call each other, so only the gateway knows who is answering.

```js
const [{ data: page }, { data: health }] = await Promise.all([
  api.get(`/v1/sites/${siteId}/landing`),
  api.get('/status'),                     // 503 when something is down; read the body either way
])
const up = new Set(health.services.filter(s => s.status === 'up').map(s => s.name))
const sections = page.sections.filter(s => !s.service || up.has(s.service))
```

A `website` value is honest but inert in practice — the payload carrying it came
from website-service, so if that were down there would be no page to prune.

### Files — `/api/files`

| method | path | auth | notes |
|---|---|---|---|
| GET | `/api/files/{path}` | — | greedy path; only `uploads/` and `webassets/` resolve |
| POST | `/api/admin/files` | bearer | `file` (≤ 5 MB) + `folder`, one of `website, banner, content, about, client, service, product, bank, transaction, qris`. 201 with `{path, original, url}`. Every service writes into this same root through `UPLOAD_PUBLIC_ROOT`, so most uploads never come through here |

### Admin, owned here — `/api/admin` (bearer)

| method | path | notes |
|---|---|---|
| GET | `/menus` | the signed-in user's own sidebar, nested; an empty array when their group grants nothing. Menus whose service is down are left out — see below |
| GET | `/users`, `/users/{id}` | scoped to the caller's `website_id` |
| POST · PUT · DELETE | `/users`, `/users/{id}` | `password` required on create, optional on update (blank keeps the current one); you cannot delete your own account |
| GET | `/users/groups` | active access groups, for the `roles_code` picker |
| GET | `/access-groups`, `/access-groups/{id}` | `{id}` also returns `meta.selected` — the menu ids it grants |
| GET | `/access-groups/menu-tree` | the tree those grants are picked from — one tree, shared by every site; `?group={id}` adds that group's current selection, and 404s for a group belonging to another website |
| POST · PUT · DELETE | `/access-groups`, `/access-groups/{id}` | `menus[]` carries the grants |

#### A menu whose service is down is not shown

The sidebar is the gateway's, but most of the screens behind it are not:
Products is shop-service, Banner is website-service, QRIS is thirdparty-service.
With the service down, the menu used to render anyway and open a screen whose
every call answered 502 — an outage that reached the admin as a broken page
rather than a missing one.

`menus.service` names the service a screen cannot work without — a key from
`config/gateway.php`, so `website`, `shop` or `thirdparty`. `GET /api/admin/menus`
reads it against the same cached check `GET /api/status` runs, and leaves out
every row whose service is not `up`. The column is in the response too, so the
frontend can say *why* a menu it remembers is gone.

- **NULL means the gateway answers it** — Dashboard, Users, Access Groups, and
  every folder. Always shown, and the safe default for a row nobody has
  classified.
- **Folders are NULL on purpose**, even where every child is one service's. A
  folder disappears once the outage empties it, and Masterdata holds QRIS beside
  five shop rows — marking it `shop` would hide QRIS whenever shop-service went
  down.
- **A folder that was already empty stays.** Empty for grant reasons is the tree
  the access group asked for; only a folder emptied by the outage is dropped.
- **Grants are untouched.** The row stays granted, stays in
  `/access-groups/menu-tree` — you must be able to grant a menu during an
  outage — and returns on its own when the service does.
- **It fails open.** If the health check itself cannot be made, every menu is
  shown: half a sidebar would be a worse fault than the one it reports.

So with shop-service down, Commerce keeps Masterdata holding QRIS alone, and the
Price and Transaction folders go entirely; with shop **and** thirdparty down,
Commerce goes with them.

#### The menu tree

Rows, not code — [MenuSeeder](database/seeders/MenuSeeder.php) is the list, and
`service` is the column above. **One tree for the installation**, shared by
every website: the sidebar is the admin application, and that does not differ
per site. The table used to carry a `website_id` and the seeder built the same
rows once per site in `config/sites.php`; nothing ever made the copies differ,
so they were folded into one and the column dropped.

What scopes an admin is the **grant**, not the menu: `users_menugroupdetail`
names a group, a group belongs to a website, and a user reaches a screen only
through their group. What the screen then *reads* is scoped by the `website_id`
on the verified token, so two admins opening Products still see their own
catalogues. So `/access-groups/menu-tree` is not website scoped — the group
granting out of it is, and `?group=` is checked against the caller's site.

Everything commercial sits under one folder:

```
Dashboard                                        gateway
Website/                                         gateway
  Website Setting, Banner, Content, About,
  Landing Sections, Client Logos,
  Main Style Setting        website/*            website-service
Settings/                                        gateway
  Users, User Access Group  master/*             gateway
Inbox                       message              website-service
Commerce/                                        gateway
  Masterdata/                                    gateway
    Services, Categories, Products, Packages,
    Banks                   commerce/masterdata/*   shop-service
    QRIS                    commerce/masterdata/qris  thirdparty-service
  Price/                                         gateway
    Delivery Prices,
    Other Charges           commerce/price/*     shop-service
  Transaction/                                   gateway
    Selling, Balance,
    Statistics              commerce/transaction/*  shop-service
```

`menu_url` **is** the frontend route path — the sidebar navigates to whatever
the column says — so these paths and the admin frontend's routes move together.
Balance and Statistics have no screen yet; the sidebar entry is what says one is
coming, and the frontend's placeholder is what an unbuilt one resolves to.

Within a level the order is by id, which is insertion order, so a folder added
later sorts after its siblings regardless of where it is declared in the seeder.
There is no ordering column.

### Admin, forwarded — `/api/admin` (bearer)

Verified here, then verified again by the service, so a forwarded admin request
has been vouched for twice. The paths are identical on both sides, which is why
one invocable controller per service relays all of them; the inventory is
written out in [routes/api.php](routes/api.php), grouped by service and in the
same order as each service's own route file.

| resource | forwarded to |
|---|---|
| `website`, `styles`, `sections`, `banners`, `contents`, `abouts`, `clients`, `messages` | website-service |
| `services`, `categories`, `products`, `packages`, `other-charges`, `banks`, `delivery-prices`, `transactions` | shop-service |
| `shipping/mapping/sync`, `qris` (the codes a site is paid into) | thirdparty-service |

> **Uploads are POST, not PUT** — PHP does not parse a multipart body on PUT, so
> any endpoint that can carry a file uses POST for update too. That is why
> `POST /admin/banners/{id}` sits next to `PUT /admin/categories/{id}`.

## Reading the code

```
routes/api.php            every route, grouped by who answers it. Start here.
bootstrap/app.php         API-only wiring; JSON exception rendering
config/gateway.php        service URLs, timeout, the shared gateway token
app/
  Http/Controllers/Api/
    AuthController        login, me, refresh, logout — the only password check
    FileController        serve + store files; the allowed roots and folders
    StatusController      which services are answering (no token)
    SiteController        public reads  -> website-service (path per action)
    ShopController        public reads  -> shop-service    (path per action)
    Admin/
      ForwardedAdminController    the forwarding mechanism, one line of it
      {Website,Shop,Thirdparty}AdminController   which service, nothing else
      UserController, GroupMenuController, MenuController   owned here
  Services/Gateway/ServiceProxy   header policy, multipart, 502, Set-Cookie
  Services/Gateway/ServiceStatus  pings every service at once, for /api/status
  Services/Helper/UploadService   filename generation, the shared docroot
  Http/Middleware/CheckMenuAccess menu-level gating (written, not yet applied)
  Exceptions/ApiExceptionHandler  the one place a failure becomes JSON
  Helpers/helpers.php             acting_website_id(), acting_user_email()
```

[routes/api.php](routes/api.php) is the map: heavily commented, grouped by
owner, and every forwarded route written out rather than pattern-matched.

### Layers

The gateway's own features are built in four layers, and a request moves through
them in one direction only:

```
Controller    validates the request, shapes the response
    |         (Form Requests in, ApiController + Resources out)
Service       the business case: rules, transactions, orchestration
    |
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
`DB::table`. Naming a model as a type hint is fine; querying through one is not.

Repositories extend [BaseRepository](app/Repositories/BaseRepository.php), which
carries `find`, `findForWebsite`, `existsForWebsite`, `create`, `update`,
`delete` and the `forWebsite` scope. They are plain classes with no interfaces —
Laravel autowires them by constructor injection, so there is no binding table to
keep in step. `forWebsite($id)` is where nearly every query starts, and a null id
means "every website", which is how an admin reads across all of them.

## Writing code

### Forward a new public route

Two edits — the route, and the action that names the upstream path:

```php
// routes/api.php, inside the v1 /sites/{website} group
Route::get('/testimonials', [SiteController::class, 'testimonials']);
```

```php
// app/Http/Controllers/Api/SiteController.php
public function testimonials(Request $request, $website): Response
{
    return $this->site($request, "/sites/{$website}/testimonials");
}
```

`site()` prefixes `/api/v1` and forwards to website-service; `ShopController`'s
`shop()` does the same for shop-service. Naming the path per action is deliberate
here: the public URLs were designed alongside the services, so this is where the
two sides are held against each other.

### Forward a new admin route

One edit. The admin paths are identical on both sides, so the invocable
controller needs nothing added:

```php
// routes/api.php, inside the admin group, in the same order as the service's file
Route::prefix('vouchers')->group(function () {
    Route::get('/', ShopAdminController::class);
    Route::post('/', ShopAdminController::class);
    Route::get('/{id}', ShopAdminController::class);
    Route::post('/{id}', ShopAdminController::class);   // POST: may carry a file
    Route::delete('/{id}', ShopAdminController::class);
});
```

Put literal segments (`/options`, `/parents`) **before** `/{id}`, or they get
read as an id. Do not constrain an id the service does not constrain — a
non-numeric id should reach the service and get the service's own answer, rather
than a 404 the gateway invented.

### Add a resource the gateway owns

Follow the layers, and mirror the existing namespaces
(`App\Repositories\Masterdata\UserRepository` behind
`App\Services\Masterdata\UserService`):

1. **Migration + model** — `website_id` on the table if the row belongs to one
   site.
2. **Repository** extending `BaseRepository`; name each query after what its
   caller wanted (`activeForWebsite()`, not `getAll(array $filters)`).
3. **Service** returning `['status' => 'success'|'failed', 'message' => …,
   'data' => …]`, opening any transaction it needs.
4. **Form Request** for validation and a **Resource** for the response shape, so
   a column added later cannot leak into an API response by accident.
5. **Controller** extending `ApiController`: hand the service result to
   `respond()` (which turns a `failed` status into a 422), `items()` for lists,
   `notFound()` for a miss.
6. **Route** inside the `admin` group — and scope every read and write with
   `admin_website_id()`, never with a value from the request body.

### Add an admin menu

Menus are rows, not code. Add it to the array in
[MenuSeeder](database/seeders/MenuSeeder.php) — declared after its parent, and
with its `service`, which is what keeps it out of the sidebar while the service
behind it is down (`null` only if the gateway answers the screen itself) — then
add a migration in the style of
[database/migrations/2026_08_21_000005_add_section_menu.php](database/migrations/2026_08_21_000005_add_section_menu.php)
for the databases that already have their tree, and re-run the safe half of the
seed:

```bash
php artisan db:seed --class=SiteStructureSeeder
```

That migration inserts **one row**, not one per site, and one grant per group
that should see it. The older menu migrations loop over `config('sites.ids')`
because the table used to be website scoped; a new one does not.

`db:seed` splits in two: slow reference data that only a fresh install needs, and
`SiteStructureSeeder` — accounts, access groups, the menu tree — which is written
to be re-runnable on a live database. Existing accounts are left alone, so
re-seeding never resets a password that has been changed.

### Style and tests

```bash
vendor/bin/pint            # format
composer test              # config:clear + artisan test
php artisan pail           # live log tail
```

Tests run against SQLite in memory (`phpunit.xml`), so they need no database of
their own. Only the framework's example tests exist today — the proxy's header
policy and the auth flow are the two places worth covering first.

## Troubleshooting

| symptom | cause |
|---|---|
| `502 The … service is unavailable` | that service is not running, or is slower than `GATEWAY_TIMEOUT`. The URL it tried is in `storage/logs` |
| `403` from a service on every forward | `GATEWAY_TOKEN` differs between the gateway and that service. It must be identical in all four apps; restart after changing it |
| `Token invalid` on a forwarded admin route | that service's `JWT_SECRET` does not match the gateway's |
| CORS error in the browser, no status code | the request never completed — usually `localhost` vs `127.0.0.1`, or a service that is down. A real CORS problem returns a status code, just without the header |
| CORS error *with* a status code | the frontend origin is not in `CORS_ALLOWED_ORIGINS`. Check `.env.local`, not just `.env`, then `php artisan config:clear` |
| the cart empties on every request | `withCredentials` missing on the frontend, or the frontend and gateway are not on the same hostname — `cart_token` is `SameSite=lax` |
| an uploaded file 404s | it landed outside the served docroot. Set `UPLOAD_PUBLIC_ROOT`, and check `storage/logs` for `UploadService.store` |
| a config change has no effect | `php artisan config:clear`, and confirm which `.env.{APP_ENV}` is being read |

## Known gaps

- `CheckMenuAccess` is written and aliased as `menu.access` but not applied to
  any route, so menu-level authorization is still only advisory — the admin
  routes are gated by `auth:api` alone.
- `UploadService` logs every upload at info level. Useful while the shared
  docroot is being settled, noise afterwards.
- The gateway has no tests of its own beyond the framework's examples.
- The reference tables (`codes`, `glb_*`, `rajaongkirmap`, `couriers`) are
  duplicated into all four databases so no lookup needs a cross-service call.
  That is deliberate for now and worth revisiting.
