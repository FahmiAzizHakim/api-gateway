<?php

use App\Http\Controllers\Web\SitemapController;
use App\Http\Controllers\Web\SpaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The three things that have to be HTML
|--------------------------------------------------------------------------
|
| The gateway is a JSON API and stays one. These exist because they cannot be
| answered anywhere else:
|
|   /sitemap.xml   a crawler fetches it before it runs any JavaScript, so the
|                  frontend cannot produce it. The URLs in it are
|                  website-service's articles.
|
|   /assets/*      the built frontend's own fingerprinted files, for a deployment that has
|                  not copied dist/ into the public root. Where it has, the web
|                  server answers these and PHP never sees them.
|
|   everything     index.html, with an article's title, description and share
|   else           card already in the <head>. See SpaController: a social
|                  scraper does not execute scripts, so metadata written by the
|                  app is metadata those readers never see.
|
| robots.txt is deliberately not here: public/robots.txt already answers it,
| and a static file wins over a route. It should gain a `Sitemap:` line naming
| the sitemap's absolute URL once the site has a domain -- that URL cannot be
| guessed here.
|
*/

Route::get('/sitemap.xml', [SitemapController::class, 'index']);

// The path is passed on whole, `assets/` included: it is relative to the build
// root, not to this route.
Route::get('/assets/{path}', fn (string $path, SpaController $spa) => $spa->file('assets/' . $path))
    ->where('path', '.*');

/*
| Last, and as the fallback rather than a catch-all route: the API keeps its
| own 404s.
|
| A fallback runs only when nothing else matched, so /api/* reaches this only
| for a path no API route declares -- and that answer has to stay JSON, in the
| wording ApiExceptionHandler uses, because the caller is the frontend and not
| a browser bar. Everything else is a URL the frontend router may well have a
| view for, and only it can know.
*/
Route::fallback(function (Request $request, SpaController $spa) {
    if ($request->is('api/*') || $request->expectsJson()) {
        return response()->json(['message' => 'Url not found'], 404);
    }

    return $spa->show($request);
});
