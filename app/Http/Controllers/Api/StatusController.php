<?php

namespace App\Http\Controllers\Api;

use App\Services\Gateway\ServiceStatus;
use Illuminate\Http\JsonResponse;

/**
 * Is the installation answering, and which part of it is not?
 *
 * `/up` already says whether this app is running -- Laravel's own health
 * route, and what a load balancer pings. It says nothing about the three
 * services behind it, and from outside there is no way to ask: the frontend
 * knows one origin, so a service being down reaches it as a 502 on whichever
 * call happened to need that service, with nothing in it to say which one or
 * whether the rest are fine.
 *
 * This is that question asked directly. One request, every service pinged at
 * once, one word for each.
 *
 * Left public on purpose. It answers what a visitor could work out anyway by
 * loading the storefront and watching it fail, and the thing worth protecting
 * -- where the services actually live -- is not in the response: names,
 * states and timings only, never a URL.
 */
class StatusController extends ApiController
{
    protected $status;

    public function __construct(ServiceStatus $status)
    {
        $this->status = $status;
    }

    /**
     * GET /api/status
     *
     * 200 while everything is up, 503 the moment anything is not -- so a
     * monitor can watch the status code alone, and a dashboard can read the
     * body either way. The body is the same shape in both cases.
     *
     * Per service: `up` answering, `degraded` answering with something other
     * than a 2xx, `down` not answering, `unconfigured` no base URL to ask.
     * `latency_ms` is the round trip to the health route, which is the closest
     * thing to a "how slow is it today" this can honestly report.
     */
    public function __invoke(): JsonResponse
    {
        $services = $this->status->all();
        $overall  = $this->status->summarise($services);

        return response()->json([
            'data' => [
                'status'    => $overall,
                'gateway'   => [
                    'name'        => config('app.name'),
                    'status'      => ServiceStatus::UP,
                    'environment' => config('app.env'),
                ],
                'services'  => $services,
                'checked_at' => now()->toIso8601String(),
            ],
        ], $overall === ServiceStatus::UP ? 200 : 503);
    }
}
