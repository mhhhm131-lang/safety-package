<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;

class AffectedGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'name_en',
        'affected_count',
        'vulnerability_level',
        'special_considerations',
    ];

    protected $casts = [
        'affected_count' => 'integer',
    ];

    protected $attributes = [
        'affected_count' => 0,
        'vulnerability_level' => 'medium',
    ];
}
