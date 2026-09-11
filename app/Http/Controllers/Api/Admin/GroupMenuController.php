<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Masterdata\GroupMenuRequest;
use App\Services\Masterdata\GroupMenuService;

/**
 * Access groups: which admin menus a role may reach.
 *
 * A user's roles_code names one of these, and the grants under it decide what
 * their sidebar shows.
 *
 * The menu tree is one tree for the installation, shared by every website --
 * the sidebar is the admin application, and that does not differ per site (see
 * drop_website_id_from_menus). The group is what belongs to a website, so that
 * is what every route here scopes on: a group from another site is not this
 * caller's to read, edit or grant out of.
 */
class GroupMenuController extends ApiController
{
    public $service;

    public function __construct(GroupMenuService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return $this->items($this->service->getList(admin_website_id()));
    }

    /**
     * The full menu tree: what a group's grants are picked from. Pass
     * ?group={id} to get that group's current selection alongside.
     *
     * One tree for the installation, so this is not website scoped -- the
     * groups granting out of it are (see drop_website_id_from_menus). The
     * selection is, though: a group from another site is not this caller's to
     * read.
     */
    public function menuTree()
    {
        $groupId = request()->query('group');

        if ($groupId && !$this->service->getRow($groupId, admin_website_id())) {
            return $this->notFound('Group');
        }

        return response()->json([
            'data' => [
                'tree'     => $this->service->getMenuTree(),
                'selected' => $groupId ? $this->service->getSelectedMenuIds($groupId) : [],
            ],
        ]);
    }

    public function show($id)
    {
        $data = $this->service->getRow($id, admin_website_id());

        if (!$data) {
            return $this->notFound('Group');
        }

        return response()->json([
            'data' => $data,
            'meta' => ['selected' => $this->service->getSelectedMenuIds($id)],
        ]);
    }

    public function store(GroupMenuRequest $request)
    {
        $data = $request->safe()->only(['code', 'name', 'desc', 'activestatus']);
        $data['website_id'] = admin_website_id();
        $data['created_by'] = acting_user_email() ?? '_SYS_';
        $data['updated_by'] = $data['created_by'];

        return $this->respond(
            $this->service->create($data, $request->input('menus', [])),
            201
        );
    }

    public function update(GroupMenuRequest $request, $id)
    {
        // Ensure the group belongs to the caller's website.
        if (!$this->service->getRow($id, admin_website_id())) {
            return $this->notFound('Group');
        }

        $data = $request->safe()->only(['name', 'desc', 'activestatus']);
        $data['updated_by'] = acting_user_email() ?? '_SYS_';

        return $this->respond($this->service->update($id, $data, $request->input('menus', [])));
    }

    public function destroy($id)
    {
        if (!$this->service->getRow($id, admin_website_id())) {
            return $this->notFound('Group');
        }

        return $this->respond($this->service->delete($id));
    }
}
