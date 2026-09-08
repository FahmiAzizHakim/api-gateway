<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlbCity extends Model
{
    protected $table = 'glb_cities';
    protected $primaryKey = 'city_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
