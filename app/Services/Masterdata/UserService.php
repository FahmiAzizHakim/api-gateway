<?php

namespace App\Services\Masterdata;

use App\Repositories\Masterdata\UserRepository;
use Illuminate\Support\Facades\DB;

class UserService
{
    protected $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    /* =========================
     * GET LIST
     * ========================= */
    public function getList($websiteId = null)
    {
        return $this->users->listForWebsite($websiteId);
    }

    /* =========================
     * GET SINGLE ROW (scoped to website when provided)
     * ========================= */
    public function getRow($id, $websiteId = null)
    {
        return $this->users->findForWebsite($id, $websiteId);
    }

    /* =========================
     * CREATE
     * ========================= */
    public function create(array $params)
    {
        DB::beginTransaction();

        // password is plain here; the User model 'hashed' cast hashes it on save.
        $user = $this->users->create($params);
        if (!$user) {
            DB::rollBack();
            return array("status" => "failed", "message" => "Failed to create user");
        }

        DB::commit();
        return array(
            "status"  => "success",
            "message" => "User created successfully",
            "data"    => $user,
        );
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update($id, array $params)
    {
        DB::beginTransaction();

        $user = $this->users->find($id);
        if (!$user) {
            DB::rollBack();
            return array("status" => "failed", "message" => "User not found");
        }

        $this->users->update($user, $params);

        DB::commit();
        return array(
            "status"  => "success",
            "message" => "User updated successfully",
            "data"    => $user,
        );
    }

    /* =========================
     * DELETE
     * ========================= */
    public function delete($id)
    {
        $user = $this->users->find($id);

        if (!$user) {
            return array("status" => "failed", "message" => "User not found");
        }

        if (auth()->id() == $user->id) {
            return array("status" => "failed", "message" => "You cannot delete your own account.");
        }

        $this->users->delete($user);
        return array("status" => "success", "message" => "User deleted successfully");
    }
}
