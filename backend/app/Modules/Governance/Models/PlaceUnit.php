<?php

namespace App\Modules\Governance\Models;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة ١٨-٣ (قرار ٤٧): وحدة داخل مكان. النوع محدد بحسب المكان، والصفات قليلة (اسم/رقم، دور، موقع، سعة، مشغّل).
 * إدارات المكاتب وحدات من نوع department مرتبطة بوحدة الهيكل نفسها.
 */
class PlaceUnit extends Model
{
    protected $fillable = ['place_id', 'type', 'name', 'floor', 'location', 'capacity', 'operator', 'organization_unit_id', 'is_active', 'sort', 'created_by_id'];

    protected $casts = ['capacity' => 'integer', 'is_active' => 'boolean', 'sort' => 'integer'];

    /** الأنواع بحسب المكان (قرار ٤٧) — المفتاح كود المكان. */
    public const TYPES_BY_PLACE = [
        'HZ-00' => ['duty_room'],
        'HZ-01' => ['basement_level', 'parking_zone'],
        'HZ-02' => ['electrical_room'],
        'HZ-03' => ['hvac_unit'],
        'HZ-04' => ['server_hall'],
        'HZ-05' => ['restaurant', 'cafe'],
        'HZ-06' => ['department'],
        'HZ-07' => ['training_hall', 'event_hall'],
        'HZ-08' => ['store'],
    ];

    public const TYPE_LABELS = [
        'duty_room' => 'غرفة المناوبة', 'basement_level' => 'قبو', 'parking_zone' => 'منطقة مواقف', 'electrical_room' => 'غرفة كهرباء',
        'hvac_unit' => 'وحدة تكييف', 'server_hall' => 'قاعة خوادم', 'restaurant' => 'مطعم', 'cafe' => 'مقهى', 'department' => 'إدارة',
        'training_hall' => 'قاعة تدريب', 'event_hall' => 'قاعة مؤتمرات واحتفالات', 'store' => 'مستودع',
    ];

    /** الحقول التي تظهر لكل نوع (الاسم/الرقم دائماً). */
    public const FIELDS_BY_TYPE = [
        'duty_room' => ['floor', 'location'], 'basement_level' => ['location'], 'parking_zone' => ['floor', 'location', 'capacity'],
        'electrical_room' => ['floor', 'location'], 'hvac_unit' => ['floor', 'location'], 'server_hall' => ['floor', 'location'],
        'restaurant' => ['operator', 'floor', 'location', 'capacity'], 'cafe' => ['operator', 'floor', 'location', 'capacity'],
        'department' => ['floor', 'location'], 'training_hall' => ['floor', 'capacity'], 'event_hall' => ['location', 'floor', 'capacity'],
        'store' => ['floor', 'location'],
    ];

    public const FIELD_LABELS = ['name' => 'الاسم أو الرقم', 'floor' => 'الدور', 'location' => 'الموقع', 'capacity' => 'السعة (شخصاً)', 'operator' => 'المشغّل'];

    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }

    public function getTypeLabelAttribute(): string { return self::TYPE_LABELS[$this->type] ?? $this->type; }

    public static function typesFor(string $placeCode): array { return self::TYPES_BY_PLACE[$placeCode] ?? []; }

    /**
     * من يعدّل وحدات المكان (قرار ٤٧، وبكلمته: القاعات لمدير المرافق):
     * مسؤول السلامة والمناوب ← الكل؛ مدير المرافق والصيانة ← كل ما يخص المبنى (كل الأنواع عدا الإدارات)؛
     * مدير الإدارة (أو من له إدارة) ← إدارته فقط. الباقي قراءة.
     */
    public static function canManage(User $user, Place $place, string $type, ?int $organizationUnitId = null): bool
    {
        $role = $user->role();
        if (in_array($role, ['system_admin', 'system_staff'], true)) return true;
        if ($type === 'department') {
            $mine = UserProfile::where('user_id', $user->id)->value('organization_unit_id');
            return $mine && $organizationUnitId && (int) $mine === (int) $organizationUnitId
                && PermissionRegistry::uiRole($role) === 'dept';
        }
        return $role === 'facilities_manager';
    }

    /** هل يملك أي تعديل في هذا المكان (لإظهار النموذج). */
    public static function canManageAny(User $user, Place $place): bool
    {
        foreach (self::typesFor($place->code) as $t) {
            if ($t === 'department') {
                $mine = UserProfile::where('user_id', $user->id)->value('organization_unit_id');
                if ($mine && self::canManage($user, $place, 'department', (int) $mine)) return true;
                continue;
            }
            if (self::canManage($user, $place, $t)) return true;
        }
        return in_array($user->role(), ['system_admin', 'system_staff'], true);
    }
}
