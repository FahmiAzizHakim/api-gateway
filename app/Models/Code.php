<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Code extends Model
{
    protected $table = 'codes';

    protected $fillable = [
        'parentcode',
        'code',
        'name',
        'description',
        'value',
        'datatype',
        'order',
    ];

    /**
     * All child codes of a parent (e.g. Code::children('STS') for statuses).
     */
    public function scopeChildren($query, $parentcode)
    {
        return $query->where('parentcode', $parentcode)->orderBy('order')->orderBy('id');
    }
}
