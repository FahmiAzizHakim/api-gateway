<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Gateway\ServiceProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for the admin routes a service owns and the gateway only relays.
 *
 * Unlike SiteController and ShopController, which name each upstream path
 * because the public URLs were designed alongside them, the admin URLs are
 * already identical on both sides: /api/admin/products here is
 * /api/admin/products there. So there is nothing for a per-endpoint method to
 * say. Sixty-eight of them would restate the path they forward to and give
 * sixty-eight places for a typo to hide.
 *
 * The inventory lives in routes/api.php instead, where every forwarded route
 * is written out and grouped by the service that answers it. That file is the
 * one worth reading against a service's own routes/api.php; this class is the
 * mechanism, and there is one line of it.
 *
 * Only a declared route reaches here, so the path being echoed back is one
 * this app matched -- not whatever a caller typed.
 *
 * What the gateway still adds, and why this is not a transparent proxy: the
 * admin group is wrapped in 'auth:api', so the JWT is verified and checked
 * against the blacklist before any of this runs; ServiceProxy then states the
 * caller's identity and the gateway's own token on the way out. A service
 * receives a request that has already been vouched for twice.
 */
abstract class ForwardedAdminController extends ApiController
{
    /**
     * Which entry in config('gateway.services') answers these routes.
     */
    protected $service = '';

    protected $proxy;

    public function __construct(ServiceProxy $proxy)
    {
        $this->proxy = $proxy;
    }

    public function __invoke(Request $request): Response
    {
        return $this->proxy->forward($this->service, $request, '/' . $request->path());
    }
}
