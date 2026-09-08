<?php

namespace App\Modules\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** أحد الأماكن التسعة (٨+١) — SOURCE.md §٢. */
class Place extends Model
{
    protected $fillable = ['code', 'name', 'sort'];

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public static function idByCode(?string $code): ?int
    {
        if (!$code) return null;
        return static::where('code', $code)->value('id');
    }
}
