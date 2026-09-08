<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'menus';

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id');
    }

    /**
     * Active children, eager-loaded recursively (for the access tree).
     */
    public function childrenRecursive()
    {
        return $this->hasMany(Menu::class, 'parent_id')
            ->where('activestatus', 1)
            ->orderBy('id')
            ->with('childrenRecursive');
    }
}

