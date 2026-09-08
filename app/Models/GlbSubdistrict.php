<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlbSubdistrict extends Model
{
    protected $table = 'glb_subdistricts';
    protected $primaryKey = 'subdistrict_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
