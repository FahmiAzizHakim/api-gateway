<?php

namespace App\Services\Seo;

use App\Services\Gateway\ServiceClient;
use Illuminate\Support\Facades\Cache;

/**
 * What the HTML responses need from website-service, cached.
 *
 * The gateway relays requests for a living; these two responses are the
 * exception -- it reads the article itself, to write the article's own metadata
 * into a page before serving it. So this is ServiceClient (the gateway asking
 * on its own behalf) rather than ServiceProxy (the gateway passing something
 * on), and everything is cached, because the caller is usually a crawler.
 *
 * A crawler walks every URL on the site in a few seconds. Each of those pages
 * needs the portal, the website and one article; uncached, a crawl is a small
 * flood aimed at a service that has no idea it is being crawled. The TTL is
 * short (see config/seo.php) so a corrected headline is not stale for long.
 *
 * Nothing here throws or reports failure in detail, and that is deliberate:
 * every caller wants the same thing from a failure, which is to serve the page
 * without the extra tags rather than to serve an error. A missing answer is
 * null.
 */
class SiteReader
{
    public function __construct(protected ServiceClient $client)
    {
    }

    /**
     * The website id a slug is served under, straight from the portal.
     *
     * Not configured here on purpose: which site answers on which path is
     * website-service's decision (PortalService), and the frontend resolves it
     * the same way for the same reason. A copy in config would be a second
     * source of truth that goes wrong quietly when a site is added.
     */
    public function websiteIdFor(string $slug): ?int
    {
        $portal = $this->remember('seo:portal', fn () => $this->read('/api/v1/portal'));

        foreach ($portal['options'] ?? [] as $option) {
            if (($option['slug'] ?? null) === $slug) {
                $id = $option['website']['id'] ?? null;

                return $id ? (int) $id : null;
            }
        }

        return null;
    }

    /**
     * The site's identity: its name, the company behind it, its logo.
     *
     * `/sites/{id}` answers `{ data: { website, tagline, ... } }` -- the
     * website nested inside a description of the site -- so the website itself
     * is what is returned here. Callers want the name, not the envelope.
     */
    public function website(int $websiteId): ?array
    {
        $site = $this->remember(
            "seo:website:{$websiteId}",
            fn () => $this->read("/api/v1/sites/{$websiteId}")
        );

        return $site === null ? null : ($site['website'] ?? $site);
    }

    /** One article, body included -- which is where the description comes from. */
    public function article(int $websiteId, int $id): ?array
    {
        return $this->remember(
            "seo:article:{$websiteId}:{$id}",
            fn () => $this->read("/api/v1/sites/{$websiteId}/contents/{$id}")
        );
    }

    /** Every published article for a site, as the sitemap lists them. */
    public function articles(int $websiteId): array
    {
        $articles = $this->remember(
            "seo:articles:{$websiteId}",
            fn () => $this->read("/api/v1/sites/{$websiteId}/contents")
        );

        return is_array($articles) ? $articles : [];
    }

    /**
     * Read a path, or null.
     *
     * A 404 and an unreachable service are the same answer here -- there is
     * nothing to write into the head either way -- so the distinction is left
     * to ServiceClient's own logging rather than repeated in every caller.
     */
    protected function read(string $path): ?array
    {
        $result = $this->client->get('website', $path);

        return ($result['success'] ?? false) ? ($result['data'] ?? null) : null;
    }

    /**
     * Cache::remember, except that a failure is not cached.
     *
     * remember() stores whatever the closure returns, null included, so a
     * service that was restarting when the first crawler arrived would keep
     * every page bare for the whole TTL.
     */
    protected function remember(string $key, callable $resolve): ?array
    {
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $resolve();

        if (is_array($fresh)) {
            Cache::put($key, $fresh, (int) config('seo.cache_ttl', 300));
        }

        return $fresh;
    }
}
