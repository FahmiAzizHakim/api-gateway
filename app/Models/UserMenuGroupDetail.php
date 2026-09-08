<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Menu;


class UserMenuGroupDetail extends Model
{
    protected $table = 'users_menugroupdetail';

    public function menu(): BelongsTo
{
    return $this->belongsTo(
        Menu::class,
        'menu_id'
    );
}
}
