<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Menu;
use App\Models\UserMenuGroupDetail;


class UserMenuGroup extends Model
{
    protected $table = 'users_menugroup';

    protected $fillable = [
        'website_id',
        'code',
        'name',
        'desc',
        'activestatus',
        'created_by',
        'updated_by',
    ];

    public function groupDetails(): HasMany
    {
        return $this->hasMany(
            UserMenuGroupDetail::class,
            'usergroup_id'
        );
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(
            Menu::class,
            'users_menugroupdetail',
            'usergroup_id',
            'menu_id'
        )->withTimestamps();
    }
}

