<?php

use App\Exceptions\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * The gateway speaks JSON, with three exceptions -- see routes/web.php. It
 * serves the built frontend's index.html with an article's metadata already in
 * the <head>, its /assets/*, and /sitemap.xml, because none of those can be
 * produced by an app that renders in the browser: a social scraper and a
 * crawler fetching a sitemap both read the response as sent.
 *
 * Everything else is still JSON, and every failure is still rendered by
 * ApiExceptionHandler rather than by Laravel's HTML error pages.
 *
 * Sessions still exist in config because the users table carries them, but
 * nothing routes through a session -- the frontend authenticates with a
 * bearer token.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Loaded after the API group, and holding only a fallback plus two
        // named files, so no route here can shadow /api/*.
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'menu.access' => \App\Http\Middleware\CheckMenuAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $e, $request) => ApiExceptionHandler::render($e));
    })->create();
