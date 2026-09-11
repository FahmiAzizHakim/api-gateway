<?php

namespace App\Repositories;

use App\Models\Menu;

/**
 * The admin menu tree. Two readings of the same table: what one role is
 * allowed to see, and the whole tree an access group is edited against.
 */
class MenuRepository extends BaseRepository
{
    protected $model = Menu::class;

    /**
     * Active menus directly under $parentId that $roleId holds a grant for.
     *
     * The grant is a row in users_menugroupdetail, joined here as a subquery
     * rather than a relation because the caller walks the tree one level at a
     * time and only ever needs the ids.
     */
    public function childrenForRole($roleId, $parentId = null)
    {
        return $this->query()
            ->where('parent_id', $parentId)
            ->where('activestatus', 1)
            ->whereIn('id', function ($query) use ($roleId) {
                $query->select('menu_id')
                    ->from('users_menugroupdetail')
                    ->where('usergroup_id', $roleId);
            })
            ->get();
    }

    /**
     * Every active top-level menu with its descendants loaded -- the full tree
     * an access group's checkboxes are drawn from, ungated by any role.
     *
     * Not scoped to a website, because the table is not: there is one tree and
     * every site's groups grant out of it (see drop_website_id_from_menus).
     * The group doing the granting is what belongs to a website, and
     * GroupMenuController checks that before it gets here.
     */
    public function tree()
    {
        return $this->query()
            ->whereNull('parent_id')
            ->where('activestatus', 1)
            ->orderBy('id')
            ->with('childrenRecursive')
            ->get();
    }
}
