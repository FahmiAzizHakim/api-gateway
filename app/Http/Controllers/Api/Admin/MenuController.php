<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\MenuService;

/**
 * The signed-in user's own sidebar: the menu tree their access group grants,
 * nested, with folders carrying their children.
 *
 * This is what the admin frontend renders its navigation from, so it answers
 * for the caller rather than taking a group id.
 */
class MenuController extends ApiController
{
    public $service;

    public function __construct(MenuService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $user = auth('api')->user();

        // No token yet (auth is still being built), or an account with no
        // group: an empty sidebar rather than an error.
        $group = $user ? $user->menuGroup : null;

        if (!$group) {
            return $this->items([]);
        }

        return $this->items($this->service->getMenus($group->id));
    }
}
