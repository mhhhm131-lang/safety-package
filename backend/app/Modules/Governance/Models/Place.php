<?php

namespace App\Modules\Governance\Models;

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مكان من أماكن المبنى: صنفه واحد من ٨+١ (SOURCE.md §٢) ومبناه واحد.
 * ٢٨-١ (قرار ٧٨): `category` هو الصنف (HZ-00…HZ-08) و`code` رمز المكان الفريد: في الملز رمزه صنفه كما كان،
 * وفي الفرع صنفه ثم رمز مبناه على نمط قرار ٢٩ (`HZ-06/DMM`). صنف واحد لكل مبنى.
 */
class Place extends Model
{
    /** ١٩-٦ (قرار ٤٩): هاتف مركز السلامة كما في صفحة البلاغ — يُعرض مع «مكاني» وفي ملف المكان */
    public const CENTER_PHONE = '0505498966';

    // max_workers/max_equipment: سعة المكان كمنطقة عمل (المرحلة ٦-ب) — null يعني بلا حد.
    protected $fillable = ['code', 'category', 'name', 'sort', 'is_active', 'max_workers', 'max_equipment', 'building_id'];

    protected $casts = ['max_workers' => 'integer', 'max_equipment' => 'integer', 'is_active' => 'boolean'];

    /** الأصناف التسعة (٨+١) بأسمائها المعتمدة وترتيبها — SOURCE.md §٢. كل مبنى أماكنه من هذه الأصناف. */
    public const CATEGORIES = [
        ['HZ-00', 'مركز السلامة'],
        ['HZ-01', 'القبو ومواقف السيارات'],
        ['HZ-02', 'غرف الكهرباء'],
        ['HZ-03', 'غرف التكييف'],
        ['HZ-04', 'مركز البيانات'],
        ['HZ-05', 'المطاعم'],
        ['HZ-06', 'المكاتب الإدارية'],
        ['HZ-07', 'القاعات التدريبية'],
        ['HZ-08', 'المخازن'],
    ];

    /** ٢٨-٢: المكان المعطَّل = صنف لا يوجد في هذا المبنى — لا يظهر في القوائم ولا في النطاق */
    public function scopeActive($q) { return $q->where('is_active', true); }

    /** ٢٨-٢: رمز المكان في المبنى — الملز رمزه صنفه، وغيره صنفه ثم رمز مبناه (قرار ٢٩) */
    public static function codeFor(string $category, EmergencyBuilding $b): string
    {
        return $b->id === EmergencyBuilding::main()?->id ? $category : $category.'/'.trim((string) $b->code);
    }

    /**
     * ٢٨-٢: «أماكن المبنى» بضغطة — الأصناف التسعة الناقصة في المبنى تُنشأ بأسمائها ورموزها. الموجود لا يُمس.
     * @return string[] رموز ما أُنشئ
     */
    public static function createCategoriesFor(EmergencyBuilding $b): array
    {
        $isMain = $b->id === EmergencyBuilding::main()?->id;
        if (!$isMain && trim((string) $b->code) === '') {
            throw new \InvalidArgumentException('أعطِ المبنى رمزاً قصيراً أولاً من زر «تعديل» (مثل DMM) — يدخل في رموز أماكنه: HZ-06/DMM');
        }
        $have = static::where('building_id', $b->id)->pluck('category')->all();
        $created = [];
        foreach (self::CATEGORIES as $i => [$cat, $name]) {
            if (in_array($cat, $have, true)) continue;
            $p = static::create(['code' => self::codeFor($cat, $b), 'category' => $cat, 'name' => $name, 'sort' => $i, 'building_id' => $b->id]);
            $created[] = $p->code;
        }
        return $created;
    }

    /** ٢٠-١ (قرار ٥١): الفرع ← المبنى ← المكان — مكان بلا مبنى يتبع الرئيسي (الملز). ٢٨-١: مكان بلا صنف صنفه ما قبل «/» في رمزه */
    protected static function booted(): void
    {
        static::creating(function (Place $p) {
            $p->building_id ??= EmergencyBuilding::mainOrCreate()->id;
            $p->category ??= self::categoryOf((string) $p->code);
        });
    }

    /** الصنف من الرمز: `HZ-06` ← `HZ-06`، و`HZ-06/DMM` ← `HZ-06` */
    public static function categoryOf(string $code): string
    {
        return explode('/', $code, 2)[0];
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
        $f = '/'.(self::FOLDERS[$this->category] ?? $this->category); // ٢٨-٢: وثائق الصنف لكل مكان منه في كل مبنى
        $links = [
            ['فهرس المكان', "$f/index.html"],
            ['خطة السلامة', "$f/safety-plan.html"],
        ];
        if ($this->category !== 'HZ-00') {
            $links[] = ['خطة الاستجابة', "$f/response-plan.html"];
            $links[] = ['نموذج الفحص', "$f/inspection-form.html"];
        } else {
            $links[] = ['الجاهزية (٧)', "$f/inspection-form.html"];
            $links[] = ['الحريق (١٢)', "$f/fire-inspection.html"];
        }
        $links[] = ['ملف المكان', '/app/places/'.$this->id.'/file']; // ١٩-٧: في الخلفية
        $links[] = ['مخاطر المكان', '/app/risk/active?place='.$this->code];
        $links[] = ['بلاغات الشاغلين', '/app/incidents?place='.$this->code];
        $links[] = ['رمز QR', '/app/places/'.$this->id.'/qr']; // ٢٨-٢: بالمعرّف — الرمز قد يحمل «/»
        return $links;
    }
}
