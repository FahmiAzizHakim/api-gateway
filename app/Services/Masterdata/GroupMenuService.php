<?php

namespace App\Services\Masterdata;

use App\Repositories\Masterdata\GroupMenuRepository;
use App\Repositories\Masterdata\UserRepository;
use App\Repositories\MenuRepository;
use Illuminate\Support\Facades\DB;

class GroupMenuService
{
    protected $groups;
    protected $menus;
    protected $users;

    public function __construct(
        GroupMenuRepository $groups,
        MenuRepository $menus,
        UserRepository $users
    ) {
        $this->groups = $groups;
        $this->menus  = $menus;
        $this->users  = $users;
    }

    /* =========================
     * GET LIST
     * ========================= */
    public function getList($websiteId = null)
    {
        return $this->groups->listForWebsite($websiteId);
    }

    /* =========================
     * GET SINGLE ROW (scoped to website when provided)
     * ========================= */
    public function getRow($id, $websiteId = null)
    {
        return $this->groups->findForWebsite($id, $websiteId);
    }

    /* =========================
     * ACTIVE GROUPS for the roles_code picker on the user form
     * ========================= */
    public function getActiveList($websiteId = null)
    {
        return $this->groups->activeForWebsite($websiteId);
    }

    /* =========================
     * MENU TREE (active menus for the website, nested by parent)
     * ========================= */
    public function getMenuTree($websiteId = null)
    {
        return $this->menus->treeForWebsite($websiteId);
    }

    /* =========================
     * SELECTED MENU IDS FOR A GROUP
     * ========================= */
    public function getSelectedMenuIds($id): array
    {
        return $this->groups->selectedMenuIds($id);
    }

    /* =========================
     * CREATE
     * ========================= */
    public function create($params, array $menuIds = [])
    {
        DB::beginTransaction();

        $group = $this->groups->create($params);
        if (!$group) {
            DB::rollBack();
            return array("status" => "failed", "message" => "Failed to create group");
        }

        $this->groups->syncMenus($group, $menuIds);

        DB::commit();
        return array(
            "status"  => "success",
            "message" => "Group created successfully",
            "data"    => $group,
        );
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update($id, $params, array $menuIds = [])
    {
        DB::beginTransaction();

        $group = $this->groups->find($id);
        if (!$group) {
            DB::rollBack();
            return array("status" => "failed", "message" => "Group not found");
        }

        $this->groups->update($group, $params);
        $this->groups->syncMenus($group, $menuIds);

        DB::commit();
        return array(
            "status"  => "success",
            "message" => "Group updated successfully",
            "data"    => $group,
        );
    }

    /* =========================
     * DELETE
     * ========================= */
    public function delete($id)
    {
        $group = $this->groups->find($id);

        if (!$group) {
            return array("status" => "failed", "message" => "Group not found");
        }

        // Block deletion while users still belong to this group.
        $inUse = $this->users->countInGroup($group->code);
        if ($inUse > 0) {
            return array(
                "status"  => "failed",
                "message" => "Cannot delete: {$inUse} user(s) still belong to this group.",
            );
        }

        DB::beginTransaction();
        $this->groups->detachMenus($group);   // remove access rows first (FK)
        $this->groups->delete($group);
        DB::commit();

        return array("status" => "success", "message" => "Group deleted successfully");
    }
}
