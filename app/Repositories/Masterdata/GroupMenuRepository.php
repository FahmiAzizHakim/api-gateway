<?php

namespace App\Repositories\Masterdata;

use App\Models\UserMenuGroup;
use App\Repositories\BaseRepository;

/**
 * Access groups and the menu grants attached to them.
 *
 * The grants are a pivot, so they are written through the relation rather than
 * as rows: sync() is what makes "these are the group's menus now" one
 * statement instead of a diff the caller has to compute.
 */
class GroupMenuRepository extends BaseRepository
{
    protected $model = UserMenuGroup::class;

    public function listForWebsite($websiteId = null)
    {
        return $this->query()
            ->withCount('groupDetails')
            ->when($websiteId, fn ($q) => $q->where('website_id', $websiteId))
            ->orderBy('id')
            ->get();
    }

    /**
     * The groups an account may actually be put into, by name.
     *
     * Ordered by name and filtered to the live ones, because this is the
     * roles_code picker rather than the group admin's own list.
     */
    public function activeForWebsite($websiteId = null)
    {
        return $this->forWebsite($websiteId)
            ->where('activestatus', 1)
            ->orderBy('name')
            ->get();
    }

    /** The menu ids a group currently grants, for pre-ticking the editor. */
    public function selectedMenuIds($id): array
    {
        $group = $this->find($id);

        return $group ? $group->menus()->pluck('menus.id')->toArray() : [];
    }

    public function syncMenus(UserMenuGroup $group, array $menuIds): void
    {
        $group->menus()->sync($menuIds);
    }

    /** Drop every grant, which the FK requires before the group itself goes. */
    public function detachMenus(UserMenuGroup $group): void
    {
        $group->menus()->detach();
    }
}
