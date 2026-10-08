<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * وثيقة من وثائق المعهد: مفتاح واحد من مفاتيح localStorage (ipa-*) بمحتواه كما هو.
 * `data` نص JSON حرفي (لا يُحلَّل في الخادم — انظر الترحيل).
 * ٢٨-١ (قرار ٧٨): الوثيقة تحمل مبناها؛ المفتاح فريد داخل المبنى. بلا تحديد = الملز (كما كان كل شيء).
 * القراءة بمبنى الجلسة تأتي في ٢٨-٣.
 */
class InstituteDocument extends Model
{
    protected $fillable = ['key', 'data', 'version', 'updated_by', 'building_id'];

    protected $casts = [
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (InstituteDocument $d) { $d->building_id ??= \App\Modules\Emergency\Models\EmergencyBuilding::mainOrCreate()->id; });
    }

    public function building(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Emergency\Models\EmergencyBuilding::class, 'building_id');
    }

    /** المفاتيح المسموح تخزينها: ipa- ثم حروف وأرقام وشرطات، بلا الجلسة وبلا الطابور المحلي. */
    public static function isAllowedKey(string $key): bool
    {
        return (bool) preg_match('/^ipa-[a-z0-9-]{1,50}$/', $key)
            && !in_array($key, ['ipa-session', 'ipa-store-pending'], true);
    }
}
