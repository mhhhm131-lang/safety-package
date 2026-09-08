<?php

namespace App\Modules\Governance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعدادات النظام (مفتاح/قيمة). أول استعمال: مهل بلاغ الشاغل (BACKEND.md ٥-٢-ب ج) — بلا قيم افتراضية.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    /** مفاتيح المهل بالساعات لكل نوع بلاغ. null = لم تُقرر → لا مؤقت ولا تصعيد آلي. */
    public const DEADLINE_KEYS = [
        'incident.deadline_hours.urgent' => 'عاجل',
        'incident.deadline_hours.normal' => 'عادي',
        'incident.deadline_hours.secret' => 'سري',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);
        return $row && $row->value !== null && $row->value !== '' ? $row->value : $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value === '' ? null : $value, 'updated_by' => $userId]);
    }

    /** المهلة بالساعات لنوع بلاغ، أو null إن لم تُقرر. */
    public static function deadlineHours(string $type): ?float
    {
        $v = static::get("incident.deadline_hours.$type");
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }
}
