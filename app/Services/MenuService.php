<?php

namespace App\Services;

use App\Repositories\MenuRepository;

/**
 * The signed-in user's navigation, as a tree.
 *
 * The shape is the point: the repository answers one level at a time, and the
 * recursion that turns those levels into nested arrays is the logic that lives
 * here.
 */
class MenuService
{
    protected $menus;

    public function __construct(MenuRepository $menus)
    {
        $this->menus = $menus;
    }

    /**
     * The menus $role_id may see under $parent_id, with folders carrying their
     * own children.
     *
     * Only a FOLDER recurses -- a leaf is a destination, so descending into it
     * would be a query per menu item for nothing.
     */
    public function getMenus($role_id, $parent_id = null)
    {
        return $this->menus->childrenForRole($role_id, $parent_id)
            ->map(function ($menu) use ($role_id) {
                $row = [
                    'id'        => $menu->id,
                    'parent_id' => $menu->parent_id,
                    'name_in'   => $menu->name_in,
                    'name_en'   => $menu->name_en,
                    'menu_url'  => $menu->menu_url,
                    'menu_icon' => $menu->menu_icon,
                    'menu_type' => $menu->menu_type,
                    'menu_desc' => $menu->menu_desc,
                ];

                if ($row['menu_type'] === 'FOLDER') {
                    $row['children'] = $this->getMenus($role_id, $menu->id);
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
