<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate a route on the caller's access group holding a grant for one menu.
 *
 * Used as menu.access:website/banner -- the argument is the menu_url the grant
 * is recorded against, which is how the seeded menu tree and the routes stay
 * in step.
 *
 * The guard is 'api': the gateway authenticates with a bearer token, not a
 * session. Nothing applies this yet; it goes on the admin routes with the rest
 * of the auth work.
 */
class CheckMenuAccess
{
    public function handle(Request $request, Closure $next, string $menuUrl)
    {
        $user = auth('api')->user();

        if (!$user || !$user->menuGroup) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $hasAccess = $user->menuGroup->menus()
            ->where('menu_url', $menuUrl)
            ->where('activestatus', 1)
            ->exists();

        if (!$hasAccess) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
