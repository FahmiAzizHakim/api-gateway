<?php

namespace App\Services\Seo;

/**
 * The <head> an article needs to be understood by something that is not a
 * person.
 *
 * One place, one set of fields, because the same values are wanted by three
 * different readers and each wants them spelled its own way: a search engine
 * reads <title>, the description and the JSON-LD; a social scraper reads the
 * og: properties; Twitter reads its own name= tags. They are not alternatives
 * to one another, so all three are written.
 *
 * Everything is marked with data-page-meta, which is what vue-vite's
 * usePageMeta() clears before writing its own. Without the attribute the page
 * would carry two of every tag once the app booted -- and a crawler reading
 * two different og:title values picks one, not necessarily this one.
 */
class SeoTags
{
    /** The attribute the frontend recognises as "the head manager owns this". */
    public const MANAGED = 'data-page-meta';

    /**
     * One article.
     *
     * `$article` is website-service's ContentResource and `$website` its
     * WebsiteResource -- both as plain arrays, straight off the API, because
     * that is what the gateway has: it holds no models of its own.
     */
    public static function forArticle(array $article, ?array $website, string $canonical): string
    {
        $title       = (string) ($article['title'] ?? '');
        $siteName    = $website['name'] ?? null;
        $publisher   = $website['company_name'] ?? $website['name'] ?? null;
        $description = static::description($article);
        $image       = $article['media'] ?? null;
        $author      = $article['reference'] ?? null;

        return static::render([
            // The headline first, then who published it -- how a result reads.
            'title'       => $siteName ? "{$title} — {$siteName}" : $title,
            'description' => $description,
            'canonical'   => $canonical,
            'image'       => $image,
            'image_alt'   => $title,
            'type'        => 'article',
            'site_name'   => $publisher,
            'locale'      => 'id_ID',
            'published'   => $article['created_at'] ?? null,
            'modified'    => $article['updated_at'] ?? null,
            'author'      => $author,
            'json_ld'     => [
                '@context'      => 'https://schema.org',
                '@type'         => 'NewsArticle',
                'headline'      => $title,
                'description'   => $description,
                'image'         => $image ? [$image] : null,
                'datePublished' => $article['created_at'] ?? null,
                'dateModified'  => $article['updated_at'] ?? null,
                // An article with no byline was written by the site, not by
                // nobody.
                'author' => $author
                    ? ['@type' => 'Person', 'name' => $author]
                    : ($publisher ? ['@type' => 'Organization', 'name' => $publisher] : null),
                'publisher' => $publisher ? [
                    '@type' => 'Organization',
                    'name'  => $publisher,
                    'logo'  => empty($website['logo'])
                        ? null
                        : ['@type' => 'ImageObject', 'url' => $website['logo']],
                ] : null,
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            ],
        ]);
    }

    /**
     * A URL the storefront has a route for but no content behind.
     *
     * The app renders a sentence and answers 200, because the route resolves
     * in the browser and the server never sees the miss -- a soft 404, which a
     * crawler indexes as a real but empty page. Served with a 404 status by
     * the controller and with noindex here, so neither reader is misled.
     */
    public static function forMissing(string $title, ?string $siteName = null): string
    {
        return static::render([
            'title'     => $siteName ? "{$title} — {$siteName}" : $title,
            'noindex'   => true,
            'type'      => 'website',
            'site_name' => $siteName,
        ]);
    }

    /**
     * The excerpt, or the body cut down to one.
     *
     * `description` is written to be exactly this. The body is the fallback,
     * because an article saved without an excerpt should still say something
     * in a search result -- stripped of its markup, and cut on a word rather
     * than mid-sentence.
     */
    protected static function description(array $article): ?string
    {
        $text = trim((string) ($article['description'] ?? ''));

        if ($text === '') {
            // The editor's HTML. Tags out, entities decoded, runs of space
            // collapsed -- otherwise the description opens with the article's
            // own indentation.
            $body = html_entity_decode(
                strip_tags((string) ($article['body'] ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        }

        if ($text === '') {
            return null;
        }

        $max = (int) config('seo.description_length', 155);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut       = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, " \t\n\r,.;:") . '…';
    }

    /**
     * The tags themselves.
     *
     * Anything empty is left out rather than written blank: an empty
     * og:description is worse than none at all, because it overrides the
     * description a scraper would otherwise take from the page.
     */
    protected static function render(array $meta): string
    {
        $lines = ['<title>' . e($meta['title'] ?? '') . '</title>'];

        $lines[] = static::tag('meta', ['name' => 'description', 'content' => $meta['description'] ?? null]);
        $lines[] = static::tag('link', ['rel' => 'canonical', 'href' => $meta['canonical'] ?? null]);

        if (!empty($meta['noindex'])) {
            $lines[] = static::tag('meta', ['name' => 'robots', 'content' => 'noindex, follow']);
        }

        /*
         * og: tags carry `property`, not `name`: they are RDFa, and a scraper
         * looking for property="og:title" does not find name="og:title".
         * Twitter's own tags are the other way round, which is not a slip.
         */
        $lines[] = static::tag('meta', ['property' => 'og:type', 'content' => $meta['type'] ?? 'website']);
        $lines[] = static::tag('meta', ['property' => 'og:title', 'content' => $meta['title'] ?? null]);
        $lines[] = static::tag('meta', ['property' => 'og:description', 'content' => $meta['description'] ?? null]);
        $lines[] = static::tag('meta', ['property' => 'og:url', 'content' => $meta['canonical'] ?? null]);
        $lines[] = static::tag('meta', ['property' => 'og:site_name', 'content' => $meta['site_name'] ?? null]);
        $lines[] = static::tag('meta', ['property' => 'og:locale', 'content' => $meta['locale'] ?? null]);
        $lines[] = static::tag('meta', ['property' => 'og:image', 'content' => $meta['image'] ?? null]);
        $lines[] = static::tag('meta', [
            'property' => 'og:image:alt',
            'content'  => empty($meta['image']) ? null : ($meta['image_alt'] ?? null),
        ]);

        if (($meta['type'] ?? 'website') === 'article') {
            $lines[] = static::tag('meta', ['property' => 'article:published_time', 'content' => $meta['published'] ?? null]);
            $lines[] = static::tag('meta', ['property' => 'article:modified_time', 'content' => $meta['modified'] ?? null]);
            $lines[] = static::tag('meta', ['property' => 'article:author', 'content' => $meta['author'] ?? null]);
        }

        // Without an image Twitter renders a summary card whatever it is told,
        // so the card type follows the image rather than claiming a large one.
        $lines[] = static::tag('meta', [
            'name'    => 'twitter:card',
            'content' => empty($meta['image']) ? 'summary' : 'summary_large_image',
        ]);
        $lines[] = static::tag('meta', ['name' => 'twitter:title', 'content' => $meta['title'] ?? null]);
        $lines[] = static::tag('meta', ['name' => 'twitter:description', 'content' => $meta['description'] ?? null]);
        $lines[] = static::tag('meta', ['name' => 'twitter:image', 'content' => $meta['image'] ?? null]);

        if (!empty($meta['json_ld'])) {
            $lines[] = static::jsonLd($meta['json_ld']);
        }

        // Indented to sit inside the <head> it is spliced into.
        return implode("\n    ", array_filter($lines));
    }

    /** One element, or nothing when it would have no content. */
    protected static function tag(string $name, array $attributes): ?string
    {
        if (array_key_exists('content', $attributes) && blank($attributes['content'])) {
            return null;
        }

        if (array_key_exists('href', $attributes) && blank($attributes['href'])) {
            return null;
        }

        $rendered = '<' . $name . ' ' . static::MANAGED;

        foreach ($attributes as $attribute => $value) {
            if (!blank($value)) {
                $rendered .= ' ' . $attribute . '="' . e($value) . '"';
            }
        }

        return $rendered . '>';
    }

    /**
     * The JSON-LD block.
     *
     * Nulls are dropped: schema.org treats an absent property and a null one
     * differently, and a validator complains about the second. JSON_HEX_TAG is
     * what keeps a '<' in a headline from ending the script element early.
     */
    protected static function jsonLd(array $data): string
    {
        $json = json_encode(
            static::withoutNulls($data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );

        return '<script ' . static::MANAGED . ' type="application/ld+json">' . $json . '</script>';
    }

    /** Recursively drop null, '' and [], keeping 0 and '0'. */
    protected static function withoutNulls(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = static::withoutNulls($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
