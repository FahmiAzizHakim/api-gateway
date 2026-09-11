<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Seo\SiteReader;
use Illuminate\Http\Response;

/**
 * /sitemap.xml -- every URL on this origin worth indexing.
 *
 * Nothing in the browser can produce this: a sitemap is a file a crawler
 * fetches before it runs anything, so it has to be answered by whatever serves
 * the origin. That is the gateway, and the URLs in it are website-service's
 * articles, so this is where the two meet.
 *
 * Listed: the portal, each site's landing page, and every article on it. Not
 * listed: product pages. They are real URLs and they would be indexed, but
 * they are still served as an empty document with no metadata of their own,
 * and a sitemap is a claim that a URL is worth crawling. They belong here once
 * SpaController has a case for them.
 */
class SitemapController extends Controller
{
    public function __construct(protected SiteReader $reader)
    {
    }

    public function index(): Response
    {
        $base = (string) config('seo.base_url');

        if ($base === '') {
            $base = rtrim(request()->getSchemeAndHttpHost(), '/');
        }

        // The portal, which is the only page above the sites.
        $urls = [['loc' => $base . '/', 'lastmod' => null]];

        foreach ((array) config('seo.sitemap_slugs', []) as $slug) {
            $websiteId = $this->reader->websiteIdFor((string) $slug);

            // A slug the portal does not know is a stale config entry, not a
            // reason to answer 500 and lose the rest of the sitemap.
            if ($websiteId === null) {
                continue;
            }

            $urls[] = ['loc' => $base . '/' . $slug, 'lastmod' => null];

            foreach ($this->reader->articles($websiteId) as $article) {
                if (empty($article['id'])) {
                    continue;
                }

                $urls[] = [
                    'loc' => $base . '/' . $slug . '/content/' . $article['id'],
                    // When the article last changed, which is what <lastmod>
                    // means -- not when it was published. website-service
                    // returns both; older rows where they are equal simply
                    // have not been edited.
                    'lastmod' => $article['updated_at'] ?? $article['created_at'] ?? null,
                ];
            }
        }

        return response($this->render($urls), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * The XML itself.
     *
     * Written out rather than built with a library: it is two elements deep,
     * and the only thing that needs care is escaping the URLs -- which is
     * genuine, because an article URL is built from an id but the base URL
     * comes from a header.
     */
    protected function render(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . e($url['loc']) . '</loc>';

            if (!empty($url['lastmod'])) {
                $lines[] = '    <lastmod>' . e($url['lastmod']) . '</lastmod>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }
}
