<?php

namespace App\Http\Controllers\Api;

use App\Services\Gateway\ServiceProxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public site data, forwarded to website-service.
 *
 * No token. This is what a visitor's browser reads before anyone has signed
 * in -- a site's identity, its theme, the order of its sections and its
 * articles -- so it has to be reachable by a page nobody is logged into. Every
 * route is scoped to one website id, and website-service only serves the
 * active ones, so an id is not a secret and nothing here is worth a login.
 *
 * The gateway holds none of this data. Each action names the website-service
 * route it stands for and hands the request to ServiceProxy; the answer goes
 * back to the browser as it arrived. The value is not in the forwarding but in
 * the fact that the frontend now has a single origin to talk to: one CORS
 * configuration, one host to point VITE_API_BASE at, and services that can be
 * closed to everything but this one.
 *
 * The catalogue -- services, products, packages -- is shop-service's, and gets
 * its own controller here when its turn comes. The routes are the same shape:
 * a public read under /api/v1/sites/{website}.
 */
class SiteController extends ApiController
{
    protected $proxy;

    public function __construct(ServiceProxy $proxy)
    {
        $this->proxy = $proxy;
    }

    /** GET /api/v1/portal -- one card per site, with the slug each is served under. */
    public function portal(Request $request): Response
    {
        return $this->site($request, '/portal');
    }

    /** GET /api/v1/sites -- the active sites this installation serves. */
    public function index(Request $request): Response
    {
        return $this->site($request, '/sites');
    }

    /** GET /api/v1/sites/{website} -- identity, theme tokens and layout. */
    public function show(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}");
    }

    /** GET /api/v1/sites/{website}/landing -- everything the landing page renders. */
    public function landing(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/landing");
    }

    public function styles(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/styles");
    }

    public function sections(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/sections");
    }

    public function banners(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/banners");
    }

    public function abouts(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/abouts");
    }

    /** GET /api/v1/sites/{website}/clients -- the "our clients" logo strip. */
    public function clients(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/clients");
    }

    public function contents(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/contents");
    }

    public function content(Request $request, $website, $id): Response
    {
        return $this->site($request, "/sites/{$website}/contents/{$id}");
    }

    /** GET /api/v1/sites/{website}/contents/{id}/comments -- the thread. */
    public function comments(Request $request, $website, $id): Response
    {
        return $this->site($request, "/sites/{$website}/contents/{$id}/comments");
    }

    /**
     * POST /api/v1/sites/{website}/contents/{id}/comments -- a visitor's
     * comment.
     *
     * The other public write, and rate limited on the route for the same
     * reason /contact is. website-service validates it and answers 422 with
     * the field errors, which pass straight back to the form.
     */
    public function storeComment(Request $request, $website, $id): Response
    {
        return $this->site($request, "/sites/{$website}/contents/{$id}/comments");
    }

    /**
     * POST /api/v1/sites/{website}/contact -- the contact form.
     *
     * The only public write. website-service validates it and answers 422 with
     * the field errors, which pass straight back to the form.
     */
    public function contact(Request $request, $website): Response
    {
        return $this->site($request, "/sites/{$website}/contact");
    }

    /**
     * Forward to website-service's public API. Every path here is relative to
     * its /api/v1 prefix, which is the same prefix these routes carry, so the
     * two sides read as the same URL.
     */
    protected function site(Request $request, string $path): Response
    {
        return $this->proxy->forward('website', $request, '/api/v1' . $path);
    }
}
