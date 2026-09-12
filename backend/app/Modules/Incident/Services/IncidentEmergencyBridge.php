<?php

namespace App\Modules\Incident\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentEvent;
use App\Modules\Risk\Models\RiskControl;
use App\Modules\Risk\Models\RiskPhase;
use InvalidArgumentException;

/**
 * المرحلة ١٠-٣ (قرار ٣٠، المكوّنان و + ح-٢): الجسر من بلاغ الشاغل إلى الحالة الطارئة.
 *
 *  (و) «تفعيل حالة طارئة» من صفحة البلاغ عند المركز: القرار بيد المركز لا آلياً (النداء الأول بالهاتف كما في الخطة).
 *      النوع مقترَح من صنف الخطر المربوط ويغيّره المركز؛ تُنشأ الحالة بمكان البلاغ وlinked_incident_id؛ حدث في خط البلاغ
 *      «فُعّلت الحالة ط-…»؛ الحالة تعرض «المصدر: بلاغ ش-…». البلاغ يبقى برقمه ومساره ومهله.
 *  (ح-٢) بطاقة الخطر في البلاغ تعرض الطبقة بنوع البلاغ: عادي/سري ← التشغيلية، عاجل ← الاستجابة (كلمة المستخدم ٢٠٢٦-٠٩-١٠).
 */
class IncidentEmergencyBridge
{
    /** صنف الخطر (اسمه في كتاب المعهد) ← نوع الحالة المقترح. لا صنف أو صنف آخر ← «أخرى» ويختار المركز. */
    private const CATEGORY_TYPES = [
        'حريق' => EmergencyIncident::TYPE_FIRE, 'انفجار' => EmergencyIncident::TYPE_FIRE,
        'بيولوج' => EmergencyIncident::TYPE_MEDICAL, 'صحي' => EmergencyIncident::TYPE_MEDICAL, 'كهرب' => EmergencyIncident::TYPE_MEDICAL,
        'كيميائ' => EmergencyIncident::TYPE_GAS_LEAK,
        'ميكانيك' => EmergencyIncident::TYPE_STRUCTURAL, 'إنشائ' => EmergencyIncident::TYPE_STRUCTURAL,
    ];

    /** أنواع الحالة التي يختار منها المركز (بلا التمرين والإغلاق الأمني). */
    public const TYPE_OPTIONS = ['fire', 'medical', 'gas_leak', 'chemical_spill', 'structural', 'evacuation', 'flood', 'security', 'other'];

    public function __construct(protected EmergencyService $emergency) {}

    public function proposeType(Incident $incident): string
    {
        $name = (string) ($incident->risk?->category?->name ?? '');
        foreach (self::CATEGORY_TYPES as $needle => $type) {
            if ($name !== '' && str_contains($name, $needle)) return $type;
        }
        return EmergencyIncident::TYPE_OTHER;
    }

    /**
     * المرحلة ١١-١ (د، قرار ٣٤): الخطورة المقترحة من خطورة الخطر المربوط (مقياس ١–٥، قرار ٢٧):
     * ١–٢ منخفض، ٣ متوسط، ٤ مرتفع، ٥ حرج. بلا خطر: العاجل مرتفع والعادي متوسط (الافتراض القائم في الشاشة).
     */
    public function proposeSeverity(Incident $incident): string
    {
        $s = (int) ($incident->risk?->severity ?? 0);
        if ($s >= 5) return 'critical';
        if ($s === 4) return 'high';
        if ($s === 3) return 'medium';
        if ($s >= 1) return 'low';
        return $incident->incident_type === 'urgent' ? 'high' : 'medium';
    }

    /** الحالة الطارئة المفعَّلة من هذا البلاغ إن وُجدت (أحدثها). */
    public function linkedEmergency(Incident $incident): ?EmergencyIncident
    {
        return EmergencyIncident::where('linked_incident_id', $incident->id)->orderByDesc('id')->first();
    }

    /** (و) التفعيل من البلاغ. يرمي InvalidArgumentException برسالة عربية عند المنع. */
    public function trigger(Incident $incident, User $by, string $type, string $severity = 'high', ?string $note = null): EmergencyIncident
    {
        if (in_array($incident->status, Incident::TERMINAL, true)) {
            throw new InvalidArgumentException('لا تُفعَّل حالة من بلاغ مغلق أو خارج النطاق.');
        }
        if (!in_array($type, self::TYPE_OPTIONS, true)) {
            throw new InvalidArgumentException('نوع الحالة غير معروف.');
        }
        $building = EmergencyBuilding::main();
        if (!$building) throw new InvalidArgumentException('لا مبنى مسجّلاً في وحدة الطوارئ.');
        if ($open = $building->getActiveIncident()) {
            throw new InvalidArgumentException('توجد حالة طارئة مفتوحة '.$open->incident_code.' — أنهِها أو ألغِها أولاً، أو أضف هذا البلاغ ملاحظةً فيها.');
        }
        $desc = 'من بلاغ الشاغل '.$incident->code.': '.$incident->title
            .($incident->location_text ? ' — '.$incident->location_text : '')
            .($note ? "\n".$note : '');
        $emergency = $this->emergency->triggerAlarm($building, $type, $by, $severity, false, $desc, $incident->place_id, $incident->id);

        IncidentEvent::create([
            'incident_id' => $incident->id, 'action' => 'emergency_triggered', 'from_status' => $incident->status, 'to_status' => $incident->status,
            'note' => 'فُعّلت الحالة الطارئة '.$emergency->incident_code.' ('.$emergency->getTypeLabel().') بناءً على هذا البلاغ — البلاغ يكمل مساره',
            'actor_id' => $by->id,
        ]);
        return $emergency;
    }

    /**
     * (ح-٢) الطبقة المعروضة في بطاقة الخطر بحسب نوع البلاغ.
     * @return array{key:string,label:string,phase:?RiskPhase,controls:\Illuminate\Support\Collection,why:string}
     */
    public function riskLayer(Incident $incident): array
    {
        $key = $incident->incident_type === 'urgent' ? RiskPhase::PHASE_RESPONSE : RiskPhase::PHASE_OPERATIONAL;
        $risk = $incident->risk;
        $phase = $risk?->phases()->where('phase', $key)->first();
        $controls = $risk ? $risk->controls()->where('phase', $key)->orderBy('sort_order')->get() : collect();
        return [
            'key' => $key,
            'label' => $key === RiskPhase::PHASE_RESPONSE ? 'الاستجابة' : 'التشغيلية',
            'phase' => $phase,
            'controls' => $controls,
            'why' => $key === RiskPhase::PHASE_RESPONSE ? 'بلاغ عاجل: ما يُفعل الآن، وزر التفعيل' : 'بلاغ عادي: الضوابط القائمة والإجراء التصحيحي',
        ];
    }
}
