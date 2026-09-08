<?php

namespace App\Repositories\Masterdata;

use App\Models\User;
use App\Repositories\BaseRepository;

/**
 * The accounts table. The gateway is the only app that holds one, so this is
 * the only repository in the installation that can answer who someone is.
 */
class UserRepository extends BaseRepository
{
    protected $model = User::class;

    public function listForWebsite($websiteId = null)
    {
        return $this->forWebsite($websiteId)->orderBy('id')->get();
    }

    /**
     * How many accounts still sit in one access group -- what makes deleting
     * that group unsafe.
     */
    public function countInGroup($groupCode): int
    {
        return $this->query()->where('roles_code', $groupCode)->count();
    }
}
