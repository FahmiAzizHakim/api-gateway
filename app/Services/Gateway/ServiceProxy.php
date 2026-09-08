<?php

namespace App\Services\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forwards one request to one service and hands its answer back unchanged.
 *
 * This is the whole of what makes the gateway a gateway. The services already
 * answer in the shape the frontend expects -- ['status', 'message', 'data'] --
 * so there is nothing to translate: re-encoding a response here would only
 * create a second place for the shape to drift. The body is passed through as
 * the bytes that arrived, with the status and content type that came with it.
 *
 * What the gateway does add is a boundary. The browser knows one origin, so
 * CORS is configured once; the services can be closed to everything but this
 * host; and when a token has to be checked, it is checked here, before
 * anything is forwarded.
 *
 * The upstream's own failures are its own: a 404 or a 422 from a service is
 * relayed as it stands. Only a service that cannot be reached is rewritten,
 * as a 502 -- the caller did nothing wrong, and needs to be able to tell that
 * apart from a rejected request.
 */
class ServiceProxy
{
    /**
     * Headers worth carrying across. Everything else -- Host, Content-Length,
     * the CORS request headers -- describes the browser's connection to the
     * gateway, not the gateway's to the service, and is left behind.
     *
     * Authorization is on the list for the admin routes that are still to
     * come: a service verifies the same token the gateway does, so forwarding
     * it is all the handover needs.
     */
    protected const FORWARDED = [
        'Accept',
        'Accept-Language',
        // The handover itself: a service verifies this with the same
        // JWT_SECRET and reads the caller's identity straight off the claims,
        // so forwarding it is all the handover needs.
        'Authorization',
        // The guest cart is a cookie, not a token: shop-service keys it on
        // cart_token and mints that itself (CartService::COOKIE). Without the
        // header going out and Set-Cookie coming back, every cart call through
        // the gateway would look like a first visit and start an empty basket.
        'Cookie',
    ];

    /**
     * Identity headers the gateway states rather than relays.
     *
     * X-Website-Id and X-User-Email are how a service scopes a query, so a
     * caller must not be able to set them: forwarded from the request, they
     * would let anyone name the website they wanted to act on. They are
     * written here from the token the gateway verified, and stripped from
     * whatever arrived, so the value a service reads is one this app vouched
     * for. The service prefers its own verified claims anyway -- these are the
     * belt to that braces, and what an unauthenticated forward sends: nothing.
     */
    protected const ASSERTED = [
        'X-Website-Id',
        'X-User-Email',
        // Says the request came from the gateway. Stripped from the caller's
        // headers for the same reason as the two above -- it is a secret this
        // app holds, not a value anyone may hand it -- and set below on every
        // forward, authenticated or not.
        'X-Gateway-Token',
    ];

    /**
     * Forward the current request to $service at $path.
     *
     * $path is the service's full route, /api included, so a route in this
     * app and the route it stands for can be read against each other.
     */
    public function forward(string $service, Request $request, string $path): Response
    {
        $base = rtrim((string) config("gateway.services.{$service}"), '/');

        if ($base === '') {
            // A missing base URL is a deployment mistake, not a caller's, so
            // it reads as a server fault rather than a bad request.
            Log::error("gateway: no base URL configured for service '{$service}'");

            return response()->json(['message' => 'Service not configured'], 500);
        }

        $url = $base . '/' . ltrim($path, '/');

        try {
            $upstream = Http::timeout(config('gateway.timeout'))
                ->withHeaders($this->headers($request))
                ->send($request->method(), $url, $this->options($request));
        } catch (ConnectionException $e) {
            // The one failure the frontend cannot act on: the service is down,
            // unreachable or too slow. Logged with the URL, because that is
            // the only thing that says which of the three it was.
            Log::warning("gateway: {$service} unreachable at {$url}: {$e->getMessage()}");

            return response()->json([
                'message' => 'The ' . $service . ' service is unavailable',
            ], 502);
        }

        $response = response(
            $upstream->body(),
            $upstream->status(),
            ['Content-Type' => $upstream->header('Content-Type') ?: 'application/json']
        );

        // A cookie the service minted is the service's answer as much as the
        // body is -- the guest cart is identified by nothing else -- so it is
        // relayed verbatim. Sent as a raw header rather than through the cookie
        // jar because it is already encrypted and formatted by the upstream,
        // and re-making it here would only be a chance to get it wrong.
        foreach ($upstream->headers()['Set-Cookie'] ?? [] as $cookie) {
            $response->headers->set('Set-Cookie', $cookie, false);
        }

        return $response;
    }

    /**
     * The subset of the caller's headers the service should see.
     */
    protected function headers(Request $request): array
    {
        $headers = [];

        foreach (self::FORWARDED as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->header($name);
            }
        }

        // Whatever the caller sent under these names is dropped; only a
        // signed-in request gets the identity ones back, filled in from the
        // verified user.
        foreach (self::ASSERTED as $name) {
            unset($headers[$name]);
        }

        // On every forward, including the public reads: those carry no JWT, so
        // this is the only thing the service can check them against.
        $headers['X-Gateway-Token'] = (string) config('gateway.token');

        if ($user = $this->actingUser()) {
            $headers['X-Website-Id'] = $user->website_id;
            $headers['X-User-Email'] = $user->email;
        }

        // Who is really asking. Without this every request looks to the
        // service as though it came from the gateway's own address, which
        // makes rate limiting and logs downstream useless.
        $headers['X-Forwarded-For']   = $request->ip();
        $headers['X-Forwarded-Host']  = $request->getHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();

        return $headers;
    }

    /**
     * The signed-in user, or null on a public route.
     *
     * Asked of the guard rather than the route, so a forward that happens to
     * carry a valid token states the identity even where the route did not
     * require one, and a public forward states nothing. Wrapped because the
     * guard throws rather than returning null when the token is malformed,
     * and a bad token on a public route is not the storefront's problem --
     * the route that does require one answers 401 on its own.
     */
    protected function actingUser()
    {
        try {
            return auth('api')->user();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Query string always; a body only where there is one to send.
     *
     * Most writes are JSON. The exception is a request carrying a file -- the
     * customer's payment proof, posted to a receipt -- which has to stay
     * multipart the whole way: re-encoding it as JSON is what would reach the
     * service as a missing `file` and fail validation, not the upload itself.
     *
     * The gateway's own upload endpoint (Api\FileController) is unrelated: it
     * writes into the shared document root and forwards nothing.
     */
    protected function options(Request $request): array
    {
        $options = ['query' => $request->query()];

        if (in_array($request->method(), ['GET', 'HEAD', 'DELETE'], true)) {
            return $options;
        }

        if (count($request->allFiles()) > 0) {
            $options['multipart'] = $this->multipart($request);

            return $options;
        }

        $options['json'] = $request->all();

        return $options;
    }

    /**
     * The request rebuilt as multipart parts: the files as open streams, the
     * rest of the input alongside them.
     *
     * Field names are flattened to the bracket form a form would have sent
     * (`items[0][id]`), because that is what the service's validator expects to
     * read back out of the body.
     */
    protected function multipart(Request $request): array
    {
        $parts = [];

        foreach ($request->allFiles() as $name => $files) {
            foreach (is_array($files) ? $files : [$files] as $i => $file) {
                $parts[] = [
                    'name'     => is_array($files) ? "{$name}[{$i}]" : $name,
                    'contents' => fopen($file->getRealPath(), 'r'),
                    'filename' => $file->getClientOriginalName(),
                    'headers'  => ['Content-Type' => $file->getClientMimeType()],
                ];
            }
        }

        foreach (Arr::dot($request->except(array_keys($request->allFiles()))) as $key => $value) {
            if ($value === null) {
                continue;
            }

            $parts[] = [
                'name'     => $this->bracketed($key),
                'contents' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ];
        }

        return $parts;
    }

    /** `items.0.id` as `items[0][id]`, which is how it arrived. */
    protected function bracketed(string $dotted): string
    {
        $segments = explode('.', $dotted);
        $name     = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= "[{$segment}]";
        }

        return $name;
    }
}
