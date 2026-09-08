<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Masterdata\GroupMenuRequest;
use App\Services\Masterdata\GroupMenuService;

/**
 * Access groups: which admin menus a role may reach.
 *
 * A user's roles_code names one of these, and the grants under it decide what
 * their sidebar shows. Menus are per website, so a group only ever grants its
 * own site's rows.
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
     * The full menu tree of this website: what a group's grants are picked
     * from. Pass ?group={id} to get that group's current selection alongside.
     */
    public function menuTree()
    {
        $groupId = request()->query('group');

        return response()->json([
            'data' => [
                'tree'     => $this->service->getMenuTree(admin_website_id()),
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
