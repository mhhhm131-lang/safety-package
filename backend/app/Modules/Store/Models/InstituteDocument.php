<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * وثيقة من وثائق المعهد: مفتاح واحد من مفاتيح localStorage (ipa-*) بمحتواه كما هو.
 * `data` نص JSON حرفي (لا يُحلَّل في الخادم — انظر الترحيل).
 */
class InstituteDocument extends Model
{
    protected $fillable = ['key', 'data', 'version', 'updated_by'];

    protected $casts = [
        'version' => 'integer',
    ];

    /** المفاتيح المسموح تخزينها: ipa- ثم حروف وأرقام وشرطات، بلا الجلسة وبلا الطابور المحلي. */
    public static function isAllowedKey(string $key): bool
    {
        return (bool) preg_match('/^ipa-[a-z0-9-]{1,50}$/', $key)
            && !in_array($key, ['ipa-session', 'ipa-store-pending'], true);
    }
}
