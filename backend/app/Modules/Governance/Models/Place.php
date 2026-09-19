<?php

namespace App\Modules\Governance\Models;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** أحد الأماكن التسعة (٨+١) — SOURCE.md §٢. */
class Place extends Model
{
    /** ١٩-٦ (قرار ٤٩): هاتف مركز السلامة كما في صفحة البلاغ — يُعرض مع «مكاني» وفي ملف المكان */
    public const CENTER_PHONE = '0505498966';

    // max_workers/max_equipment: سعة المكان كمنطقة عمل (المرحلة ٦-ب) — null يعني بلا حد.
    protected $fillable = ['code', 'name', 'sort', 'max_workers', 'max_equipment', 'building_id'];

    protected $casts = ['max_workers' => 'integer', 'max_equipment' => 'integer'];

    /** ٢٠-١ (قرار ٥١): الفرع ← المبنى ← المكان — مكان بلا مبنى يتبع الرئيسي (الملز) */
    protected static function booted(): void
    {
        static::creating(function (Place $p) { $p->building_id ??= EmergencyBuilding::mainOrCreate()->id; });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public static function idByCode(?string $code): ?int
    {
        if (!$code) return null;
        return static::where('code', $code)->value('id');
    }

    /** مجلد المكان في ملفات المعهد (SOURCE.md §٢) — أسماء المجلدات ثابتة. */
    public const FOLDERS = [
        'HZ-00' => 'HZ-00-safety-center', 'HZ-01' => 'HZ-01-basement', 'HZ-02' => 'HZ-02-electrical',
        'HZ-03' => 'HZ-03-hvac', 'HZ-04' => 'HZ-04-datacenter', 'HZ-05' => 'HZ-05-restaurants',
        'HZ-06' => 'HZ-06-offices', 'HZ-07' => 'HZ-07-halls', 'HZ-08' => 'HZ-08-storage',
    ];

    /** روابط وثائق المكان ونموذجه وملفه في اللوحة. مركز السلامة بلا خطة استجابة (مركز القيادة) وله نموذجا فحص. */
    public function links(): array
    {
        $f = '/'.(self::FOLDERS[$this->code] ?? $this->code);
        $links = [
            ['فهرس المكان', "$f/index.html"],
            ['خطة السلامة', "$f/safety-plan.html"],
        ];
        if ($this->code !== 'HZ-00') {
            $links[] = ['خطة الاستجابة', "$f/response-plan.html"];
            $links[] = ['نموذج الفحص', "$f/inspection-form.html"];
        } else {
            $links[] = ['الجاهزية (٧)', "$f/inspection-form.html"];
            $links[] = ['الحريق (١٢)', "$f/fire-inspection.html"];
        }
        $links[] = ['ملف المكان', '/app/places/'.$this->id.'/file']; // ١٩-٧: في الخلفية
        $links[] = ['مخاطر المكان', '/app/risk/active?place='.$this->code];
        $links[] = ['بلاغات الشاغلين', '/app/incidents?place='.$this->code];
        $links[] = ['رمز QR', '/app/places/'.$this->code.'/qr'];
        return $links;
    }
}
