<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlbDistrict extends Model
{
    protected $table = 'glb_districts';
    protected $primaryKey = 'district_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
