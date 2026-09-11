<?php

namespace App\Services\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls a service on the gateway's own behalf.
 *
 * The counterpart to ServiceProxy, and deliberately a different class. A proxy
 * relays the request that arrived: same body, same answer, nothing decided in
 * between. This is for the other case -- where the gateway does something
 * first and then needs a service, so the outgoing request is one it assembled
 * rather than one it received.
 *
 * A receipt's QRIS is that case. Answering it means reading an order's total
 * from shop-service and then asking thirdparty-service to raise a payment for
 * that amount -- two calls, with a decision in between. Relaying could not
 * express it: there would be nowhere to put the decision.
 *
 * The headers are the same handover ServiceProxy performs -- the gateway's own
 * token, the caller's bearer token, the verified identity -- because the far
 * side checks exactly the same things either way.
 */
class ServiceClient
{
    /**
     * Read from a service on the gateway's own behalf.
     *
     * For the case where the gateway needs an answer in order to decide
     * something, rather than to relay it: what an order costs, before asking
     * another service to raise a payment for that amount.
     */
    public function get(string $service, string $path, array $query = []): array
    {
        return $this->send('GET', $service, $path, ['query' => $query]);
    }

    /**
     * Post JSON to a service on the gateway's own behalf.
     */
    public function postJson(string $service, string $path, array $body): array
    {
        return $this->send('POST', $service, $path, ['json' => $body]);
    }

    /**
     * The transport both share.
     */
    protected function send(string $method, string $service, string $path, array $options): array
    {
        $base = rtrim((string) config("gateway.services.{$service}"), '/');

        if ($base === '') {
            Log::error("gateway: no base URL configured for service '{$service}'");

            return ['success' => false, 'message' => 'Service not configured', 'status' => 500];
        }

        $url = $base . '/' . ltrim($path, '/');

        try {
            $response = Http::timeout(config('gateway.timeout'))
                ->withHeaders($this->headers())
                ->send($method, $url, $options);
        } catch (ConnectionException $e) {
            Log::warning("gateway: {$service} unreachable at {$url}: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'The ' . $service . ' service is unavailable',
                'status'  => 0,
            ];
        }

        return $this->result($response, $service, $path);
    }

    /**
     * The handover, as ServiceProxy performs it.
     *
     * Read off the current request rather than passed in, for the same reason
     * ServiceProxy asks the guard rather than the route: the identity of a
     * gateway request is a property of the request, not an argument every
     * caller in between has to carry.
     */
    protected function headers(): array
    {
        $headers = [
            'Accept'          => 'application/json',
            'X-Gateway-Token' => (string) config('gateway.token'),
        ];

        $request = request();

        // The service verifies this itself with the shared JWT_SECRET, which
        // is what lets its own admin routes stay behind a token.
        if ($token = $request->header('Authorization')) {
            $headers['Authorization'] = $token;
        }

        if ($user = $this->actingUser()) {
            $headers['X-Website-Id'] = $user->website_id;
            $headers['X-User-Email'] = $user->email;
        }

        $headers['X-Forwarded-For']   = $request->ip();
        $headers['X-Forwarded-Host']  = $request->getHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();

        return $headers;
    }

    protected function actingUser()
    {
        try {
            return auth('api')->user();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A service's answer, read rather than relayed.
     *
     * The services answer ['data' => …] on success and ['message' => …] on a
     * refusal, so both are picked out here and the upstream status is carried
     * with them: a 422 from the service is the caller's problem and a 502 is
     * not, and only the status separates them.
     */
    protected function result($response, string $service, string $path): array
    {
        $body = $response->json();

        if (!$response->successful()) {
            Log::warning("gateway: {$service} refused {$path}", [
                'status'  => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return [
                'success' => false,
                // The service's own wording, which for a forwarded upstream
                // failure is Qrisly's wording -- more use than anything this
                // class could invent.
                'message' => $body['message'] ?? 'The ' . $service . ' service refused the request',
                'status'  => $response->status(),
            ];
        }

        return [
            'success' => true,
            'data'    => $body['data'] ?? null,
            'status'  => $response->status(),
            /*
             * The whole answer, for the callers that need more of it than
             * `data`.
             *
             * A service is free to say things alongside its payload -- a status
             * check answers `checked`, which says whether Qrisly was really
             * asked -- and picking out only 'data' silently drops them. Kept
             * beside the picked-out keys rather than replacing them, so every
             * existing caller reads exactly what it read before.
             */
            'body'    => is_array($body) ? $body : [],
        ];
    }
}
