<?php

namespace App\Services\Gateway;

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Whether the services behind the gateway are answering.
 *
 * The third thing this app does with a service URL, after relaying a request
 * (ServiceProxy) and asking a question of its own (ServiceClient): finding out
 * whether there is anything at the other end at all.
 *
 * It exists because the gateway is the only place that knows. The frontend
 * talks to one origin and cannot see the three hosts behind it, so a 502 on a
 * catalogue call is all it ever learns -- and a 502 does not say which service
 * is down, or whether the other two are fine. This asks all of them at once
 * and says so plainly.
 *
 * Nothing here is forwarded and nothing is authenticated on the far side: the
 * ping goes to Laravel's own health route, which every service registers in
 * bootstrap/app.php outside the api group. So a service that is up answers it
 * even if its database is unreachable and even if the gateway token is
 * misconfigured -- this reports reachability, not correctness, and the two
 * fail differently on purpose.
 */
class ServiceStatus
{
    /** Answering, with a 2xx. */
    public const UP = 'up';

    /** Something answered, but not with a 2xx: the host is there, the app is not well. */
    public const DEGRADED = 'degraded';

    /** Nothing answered before the timeout ran out. */
    public const DOWN = 'down';

    /** No base URL in config/gateway.php, so nothing was asked. */
    public const UNCONFIGURED = 'unconfigured';

    protected const CACHE_KEY = 'gateway:service-status';

    /**
     * Every configured service, checked together.
     *
     * Cached for `gateway.health.ttl` seconds -- see the config note: a page
     * left polling must not cost an outbound request per poll, and a service
     * does not recover inside that window anyway.
     */
    public function all(): array
    {
        $ttl = (int) config('gateway.health.ttl', 10);

        if ($ttl <= 0) {
            return $this->check();
        }

        return Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->check());
    }

    /**
     * Ask all three at once.
     *
     * Concurrently, because they are independent and a status page waits for
     * the slowest one either way: in series, three services each a second
     * short of the timeout would take three times as long to say the same
     * thing.
     */
    protected function check(): array
    {
        $services = (array) config('gateway.services', []);
        $path     = '/' . ltrim((string) config('gateway.health.path', '/up'), '/');
        $timeout  = (int) config('gateway.health.timeout', 3);

        $targets = [];
        $results = [];

        foreach ($services as $name => $base) {
            $base = rtrim((string) $base, '/');

            if ($base === '') {
                // A deployment mistake rather than an outage, and worth
                // saying so: a service nobody configured is not a service
                // that went down.
                $results[$name] = $this->entry($name, self::UNCONFIGURED, null, null, 'No base URL configured');

                continue;
            }

            $targets[$name] = $base . $path;
        }

        if ($targets === []) {
            return array_values($results);
        }

        // Filled in by Guzzle as each transfer finishes, which is the only way
        // to time a pooled request: the responses come back together, so the
        // clock has to be read inside each one.
        $elapsed = [];

        $responses = Http::pool(function (Pool $pool) use ($targets, $timeout, &$elapsed) {
            foreach ($targets as $name => $url) {
                $pool->as($name)
                    ->timeout($timeout)
                    // The health route answers HTML; nothing here reads the
                    // body, only the status line and how long it took.
                    ->withOptions([
                        'on_stats' => function (TransferStats $stats) use ($name, &$elapsed) {
                            $elapsed[$name] = $stats->getTransferTime();
                        },
                    ])
                    ->get($url);
            }
        });

        foreach ($targets as $name => $url) {
            $results[$name] = $this->read($name, $responses[$name] ?? null, $elapsed[$name] ?? null);
        }

        // Config order, not the order the answers came back in, so a status
        // page does not reshuffle itself between polls.
        $ordered = [];

        foreach (array_keys($services) as $name) {
            $ordered[] = $results[$name];
        }

        return $ordered;
    }

    /**
     * One service's answer, or the exception that stood in for it.
     *
     * A pooled request that could not connect returns the exception rather
     * than throwing, so both cases are unwrapped in the same place.
     */
    protected function read(string $name, $response, ?float $seconds): array
    {
        if ($response instanceof \Throwable) {
            return $this->entry($name, self::DOWN, null, $this->ms($seconds), 'Unreachable');
        }

        if ($response === null) {
            return $this->entry($name, self::DOWN, null, null, 'No response');
        }

        if ($response->successful()) {
            return $this->entry($name, self::UP, $response->status(), $this->ms($seconds));
        }

        // Something is listening and it is not well -- a 500 from the health
        // route, or a 404 because the service is older than this endpoint.
        // Reported apart from `down` because the two are fixed differently.
        return $this->entry(
            $name,
            self::DEGRADED,
            $response->status(),
            $this->ms($seconds),
            'Answered ' . $response->status() . ' on ' . config('gateway.health.path')
        );
    }

    protected function entry(string $name, string $status, ?int $httpStatus, ?int $latency, ?string $message = null): array
    {
        return [
            'name'        => $name,
            'status'      => $status,
            'http_status' => $httpStatus,
            'latency_ms'  => $latency,
            'message'     => $message,
        ];
    }

    protected function ms(?float $seconds): ?int
    {
        return $seconds === null ? null : (int) round($seconds * 1000);
    }

    /**
     * The names of the services that are answering.
     *
     * What a caller wants when it is not reporting the state but acting on it:
     * the sidebar leaves out the menus whose service is missing from this list
     * (see MenuService). Only `up` counts -- a service answering its health
     * route with a 500 cannot serve a screen either.
     *
     * Reads the same cached check GET /api/status does, so a sidebar built
     * while the status page is being polled costs no extra request.
     */
    public function available(): array
    {
        $up = array_filter($this->all(), fn (array $service) => $service['status'] === self::UP);

        return array_values(array_column($up, 'name'));
    }

    /**
     * The one word for the lot of them.
     *
     * `up` only when every service is; `down` when not one of them answered,
     * which is the shape a network or a config problem at this end takes;
     * `degraded` for everything in between, including a service nobody
     * configured -- that is a broken installation whichever way it is read.
     */
    public function summarise(array $services): string
    {
        if ($services === []) {
            return self::UNCONFIGURED;
        }

        $states = array_column($services, 'status');

        if (count(array_unique($states)) === 1 && $states[0] === self::UP) {
            return self::UP;
        }

        if (!in_array(self::UP, $states, true)) {
            return self::DOWN;
        }

        return self::DEGRADED;
    }
}
