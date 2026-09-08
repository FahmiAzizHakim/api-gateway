<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Masterdata\UserRequest;
use App\Services\Masterdata\GroupMenuService;
use App\Services\Masterdata\UserService;

/**
 * Admin accounts. The gateway owns the users table, so this is the only
 * service that has one.
 *
 * Every account belongs to exactly one website: users.website_id is what ends
 * up in the token, and what each service scopes its rows by.
 */
class UserController extends ApiController
{
    public $service;
    public $groupService;

    public function __construct(UserService $service, GroupMenuService $groupService)
    {
        $this->service      = $service;
        $this->groupService = $groupService;
    }

    public function index()
    {
        return $this->items($this->service->getList(admin_website_id()));
    }

    public function show($id)
    {
        $data = $this->service->getRow($id, admin_website_id());

        if (!$data) {
            return $this->notFound('User');
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Active access groups of this website, for the roles_code picker.
     */
    public function groups()
    {
        return $this->items($this->groupService->getActiveList(admin_website_id()));
    }

    public function store(UserRequest $request)
    {
        $data = $request->safe()->only(['name', 'email', 'password', 'roles_code', 'is_active']);
        $data['website_id'] = admin_website_id();

        return $this->respond($this->service->create($data), 201);
    }

    public function update(UserRequest $request, $id)
    {
        // Ensure the account belongs to the caller's website.
        if (!$this->service->getRow($id, admin_website_id())) {
            return $this->notFound('User');
        }

        $data = $request->safe()->only(['name', 'email', 'password', 'roles_code', 'is_active']);

        // Keep the current password when the field is left blank.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $this->respond($this->service->update($id, $data));
    }

    public function destroy($id)
    {
        if (!$this->service->getRow($id, admin_website_id())) {
            return $this->notFound('User');
        }

        return $this->respond($this->service->delete($id));
    }
}
