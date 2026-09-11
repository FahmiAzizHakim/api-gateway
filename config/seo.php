<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Serving the frontend build, with its metadata already in it
    |--------------------------------------------------------------------------
    |
    | vue-vite renders in the browser: the HTML it ships is an empty <div>, and
    | every title, description and share card is written by JavaScript once the
    | API has answered. Google runs that JavaScript. Nothing else does -- and
    | in particular no social scraper does, so a shared article was arriving at
    | WhatsApp, Facebook, LinkedIn and Slack as a bare link with no title, no
    | excerpt and no image.
    |
    | So the gateway serves index.html for the storefront's own URLs and writes
    | those tags into the <head> before sending it. Same fields the frontend
    | writes, and marked with the same data-page-meta attribute, so the app
    | replaces them on boot rather than ending up with two of each.
    |
    | This is the only part of the gateway that answers HTML. It stays here
    | rather than in the SPA because it is the one place that can: the tags
    | have to be in the response, not added to it afterwards.
    |
    */

    /*
    | Where the built frontend lives. The first of these that holds an
    | index.html wins, so a deployment that copies `vue-vite/dist/*` into the
    | gateway's public root needs no configuration, and a local checkout that
    | has only run `npm run build` is served straight out of dist.
    |
    | Set SPA_ROOT to name a directory outright.
    */
    'spa_roots' => array_values(array_filter([
        env('SPA_ROOT'),
        public_path(),
        base_path('vue-vite/dist'),
    ])),

    /*
    | The origin canonical URLs are built on.
    |
    | Empty means "whatever host this request arrived on", which is right for
    | one deployment behind one name and wrong the moment the same app answers
    | on two (a staging name, an IP, www and bare). A canonical URL exists to
    | say which of those is the real one, so name it once the site has a
    | domain.
    */
    'base_url' => rtrim((string) env('SEO_BASE_URL', ''), '/'),

    /*
    | How long an article, a website and the portal are held before being read
    | from website-service again.
    |
    | A crawler walks every URL on the site in a few seconds, and each of those
    | pages needs the same portal lookup and one article: without this, a crawl
    | is a small flood aimed at website-service. Short enough that a corrected
    | headline is not stale for long.
    */
    'cache_ttl' => (int) env('SEO_CACHE_TTL', 300),

    /*
    | The slugs the frontend actually serves, for the sitemap.
    |
    | Must match CATALOG_SLUGS in vue-vite/src/shared/config/sites.ts: both are
    | the list of sites this app renders. Website 1 (/logistic) is not one of
    | them -- it is still Blade, served by the old application, so listing it
    | here would put URLs in the sitemap that this origin does not answer.
    |
    | Which slug belongs to which website id is not configured: that is the
    | portal's answer, and it is asked for it.
    */
    'sitemap_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SEO_SITEMAP_SLUGS', 'installer,ev'))
    ))),

    /*
    | How long a meta description may be before it is cut.
    |
    | Every search engine truncates one somewhere around here, and it is better
    | to end on a whole word than to have it cut mid-sentence in a result page.
    */
    'description_length' => (int) env('SEO_DESCRIPTION_LENGTH', 155),

];
