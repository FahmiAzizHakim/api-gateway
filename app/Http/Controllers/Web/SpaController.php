<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoTags;
use App\Services\Seo\SiteReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The frontend, served with its metadata already in the HTML.
 *
 * vue-vite renders in the browser. The document it ships is an empty <div>,
 * and everything a reader-that-is-not-a-person wants -- the title, the
 * description, the share card -- is written by JavaScript after two API calls.
 * Google runs that JavaScript. No social scraper does: WhatsApp, Facebook,
 * LinkedIn and Slack read the bytes as served, which is why a shared article
 * arrived as a bare link.
 *
 * So the gateway answers the storefront's own URLs with index.html and splices
 * the tags into the <head> on the way out. The app then boots on top of the
 * same page and replaces them from its own data (they carry data-page-meta,
 * which is exactly what usePageMeta() clears), so the two never disagree and
 * never double up.
 *
 * Only the article page is enriched today, because it is the page that gets
 * shared and searched for. Every other storefront URL is served the same
 * index.html untouched -- the behaviour it has now -- so adding one is a case
 * in metaFor() rather than a change of shape.
 *
 * Nothing here is allowed to break the page. An unreachable website-service, a
 * missing article, a build that has not been copied into place: each falls
 * back to serving the file, or to the plainest possible answer. A crawler with
 * no tags is where this started; a 500 is worse.
 */
class SpaController extends Controller
{
    /** `/{slug}/content/{id}` -- the only storefront URL with its own metadata. */
    protected const ARTICLE = '#^(?<slug>[a-z0-9][a-z0-9-]*)/content/(?<id>\d+)$#';

    public function __construct(protected SiteReader $reader)
    {
    }

    /**
     * Serve the app for a storefront URL.
     *
     * Reached as the route fallback, so it answers anything the API did not:
     * `/installer`, `/installer/content/6`, `/admin/...`, and a path that is
     * nothing at all. Which of those it is only matters for the head.
     */
    public function show(Request $request): Response
    {
        $index = $this->indexFile();

        if ($index === null) {
            // Nothing to serve: the frontend has not been built, or has not
            // been copied to where config/seo.php looks for it. Said plainly,
            // because the person seeing it is a developer, not a visitor.
            return response(
                'The frontend build was not found. Run `npm run build` in vue-vite and copy dist/ into the '
                . 'gateway public root, or set SPA_ROOT.',
                503
            )->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $html = (string) file_get_contents($index);

        try {
            [$tags, $status] = $this->metaFor(trim($request->path(), '/'));
        } catch (\Throwable $e) {
            // The page is worth more than its metadata.
            Log::warning('seo: could not build meta tags: ' . $e->getMessage());
            [$tags, $status] = [null, 200];
        }

        if ($tags !== null) {
            $html = $this->inject($html, $tags);
        }

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * A file from the build -- Vite's fingerprinted `/assets/*`.
     *
     * `$path` is relative to the build root, so the route passes the whole of
     * it including the `assets/` segment.
     *
     * Only reached when the build is served from somewhere other than the
     * gateway's public root: a deployment that copies dist/ into public/ has
     * the web server answer these before PHP is ever started. It exists so
     * that a checkout which has only run `npm run build` is a working site
     * with no copying step.
     */
    public function file(string $path): BinaryFileResponse|Response
    {
        $root = $this->spaRoot();

        if ($root === null) {
            return response('Not found', 404);
        }

        // Resolved and then checked against the root: '..' in the URL must not
        // be able to read its way out of the build directory.
        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));

        if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return response('Not found', 404);
        }

        // Vite fingerprints every asset filename, so a hit can be cached hard.
        return response()->file($file, [
            'Content-Type'  => static::mimeFor($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The content type, by extension.
     *
     * Stated rather than guessed. Symfony's guesser reads the file's contents
     * and on Windows called the app's own JavaScript bundle text/plain -- and
     * a browser refuses to execute a module served as text/plain, so the page
     * loaded and did nothing. The list is short because a Vite build is: code,
     * styles, fonts, images.
     */
    protected static function mimeFor(string $file): string
    {
        $types = [
            'js'    => 'text/javascript; charset=UTF-8',
            'mjs'   => 'text/javascript; charset=UTF-8',
            'css'   => 'text/css; charset=UTF-8',
            'map'   => 'application/json; charset=UTF-8',
            'json'  => 'application/json; charset=UTF-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'webmanifest' => 'application/manifest+json',
        ];

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return $types[$extension] ?? 'application/octet-stream';
    }

    /**
     * The tags for one path, and the status the page should carry.
     *
     * Null tags mean "serve the file as it is", which is the right answer for
     * every URL this does not have anything better to say about.
     *
     * @return array{0: ?string, 1: int}
     */
    protected function metaFor(string $path): array
    {
        if (!preg_match(static::ARTICLE, $path, $matches)) {
            return [null, 200];
        }

        $websiteId = $this->reader->websiteIdFor($matches['slug']);

        if ($websiteId === null) {
            // A path shaped like an article on a site that does not exist.
            return [SeoTags::forMissing('Halaman tidak ditemukan'), 404];
        }

        $website = $this->reader->website($websiteId);
        $article = $this->reader->article($websiteId, (int) $matches['id']);

        if ($article === null) {
            /*
             * The article is gone, or website-service is down. Both answer 404
             * with noindex: an article that cannot be read must not be indexed
             * as an empty one, and a 404 tells a crawler to come back rather
             * than to drop the URL -- which is also what it should do if the
             * service was merely restarting.
             */
            return [
                SeoTags::forMissing('Artikel tidak tersedia', $website['name'] ?? null),
                404,
            ];
        }

        return [
            SeoTags::forArticle($article, $website, $this->canonical($path)),
            200,
        ];
    }

    /**
     * The absolute URL this page should be indexed under.
     *
     * From config when the site has a domain, and from the request otherwise:
     * a canonical URL naming the wrong host is worse than none, and in
     * development there is no right host to name.
     */
    protected function canonical(string $path): string
    {
        $base = (string) config('seo.base_url');

        if ($base === '') {
            $base = rtrim(request()->getSchemeAndHttpHost(), '/');
        }

        return $base . '/' . $path;
    }

    /**
     * Splice the tags into the head.
     *
     * Over the top of the document's own <title> -- which the tags include, so
     * the page never carries two -- and before </head> for a document that has
     * none. substr_replace rather than preg_replace: a '$' in a headline is a
     * backreference to preg_replace and a dollar sign to everyone else.
     */
    protected function inject(string $html, string $tags): string
    {
        if (preg_match('#<title>.*?</title>#is', $html, $match, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $tags, $match[0][1], strlen($match[0][0]));
        }

        $head = stripos($html, '</head>');

        return $head === false ? $html : substr_replace($html, $tags . "\n  ", $head, 0);
    }

    /** The build's index.html, wherever config/seo.php finds it first. */
    protected function indexFile(): ?string
    {
        $root = $this->spaRoot();

        return $root === null ? null : $root . DIRECTORY_SEPARATOR . 'index.html';
    }

    /** The first configured directory that actually holds a built frontend. */
    protected function spaRoot(): ?string
    {
        foreach ((array) config('seo.spa_roots', []) as $candidate) {
            $root = realpath((string) $candidate);

            if ($root !== false && is_file($root . DIRECTORY_SEPARATOR . 'index.html')) {
                return $root;
            }
        }

        return null;
    }
}
