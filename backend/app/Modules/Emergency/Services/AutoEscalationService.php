<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * التصعيد الآلي للحالة الطارئة (من OHSMS، مُعاد كتابته على المخطط الفعلي: النسخة الأصلية قرأت أعمدة غير موجودة —
 * started_at/logs()/headcount_* — ولم تكن مجدولة؛ الإصلاح المقرر في BACKEND.md ٥-٣).
 *
 * القواعد الخمس كما في OHSMS، والمهل بالدقائق من شاشة «المهل» (جدول settings) **بلا قيم افتراضية** — كما مهل بلاغ الشاغل
 * (قرار ٢٠٢٦-٠٩-٠٧): إن لم تُدخل المهلة لا تعمل قاعدتها. مهل OHSMS للاسترشاد: ٢/٥/٥/٣٠ دقيقة.
 *
 * المستويات: ١ الاستجابة الأولية ← ٢ المنسق والمناوب ← ٣ مسؤول السلامة والقيادة (الشؤون الإدارية والهندسية، المرافق، الأمن والسلامة)
 * ← ٤ الإدارة العليا ولجنة السلامة ← ٥ الجهات الخارجية (جهات الاتصال ذات التنبيه الآلي — نداء).
 */
class AutoEscalationService
{
    const LEVEL_INITIAL = 1;
    const LEVEL_SUPERVISOR = 2;
    const LEVEL_MANAGER = 3;
    const LEVEL_EXECUTIVE = 4;
    const LEVEL_EXTERNAL = 5;

    /** مفاتيح المهل (دقائق) في settings. null = لا قاعدة. */
    public const SETTING_KEYS = [
        'emergency.escalation.no_ack_min' => 'لا إقرار بالاستلام',
        'emergency.escalation.no_team_min' => 'لم يصل أحد من الفريق',
        'emergency.escalation.missing_min' => 'مفقودون بلا تحديث',
        'emergency.escalation.duration_min' => 'كل … دقيقة من استمرار الحالة (مستوى)',
    ];

    public const LEVEL_ROLES = [
        self::LEVEL_SUPERVISOR => ['safety_coordinator', 'system_staff'],
        self::LEVEL_MANAGER => ['system_admin', 'admin_eng_manager', 'facilities_manager', 'security_safety_head'],
        self::LEVEL_EXECUTIVE => ['top_management', 'safety_committee'],
        self::LEVEL_EXTERNAL => ['system_admin', 'system_staff'], // + نداء جهات الاتصال
    ];

    public function __construct(protected EmergencyNotificationService $notifications) {}

    public function checkAndEscalate(EmergencyIncident $incident): array
    {
        $escalations = [];
        $current = $incident->escalation_level ?? self::LEVEL_INITIAL;

        if ($this->shouldEscalateNoAck($incident)) {
            $escalations[] = $this->escalate($incident, 'no_acknowledgement', self::LEVEL_SUPERVISOR);
        }
        if ($this->shouldEscalateNoTeamResponse($incident)) {
            $escalations[] = $this->escalate($incident, 'no_team_response', self::LEVEL_MANAGER);
        }
        if ($this->shouldEscalateMissingPersonnel($incident)) {
            $escalations[] = $this->escalate($incident, 'missing_personnel', self::LEVEL_EXECUTIVE);
        }
        if (($target = $this->durationTargetLevel($incident)) !== null && ($incident->fresh()->escalation_level ?? 1) < $target) {
            $escalations[] = $this->escalate($incident, 'extended_duration', $target);
        }
        if ($incident->severity === 'critical' && !$incident->is_drill && $current < self::LEVEL_EXECUTIVE) {
            $escalations[] = $this->escalate($incident, 'critical_severity', self::LEVEL_EXECUTIVE);
        }

        return [
            'incident_id' => $incident->id,
            'previous_level' => $current,
            'escalations' => array_values(array_filter($escalations, fn ($e) => empty($e['skipped']))),
            'new_level' => $incident->fresh()->escalation_level ?? $current,
        ];
    }

    protected function escalate(EmergencyIncident $incident, string $reason, int $newLevel): array
    {
        $current = $incident->fresh()->escalation_level ?? self::LEVEL_INITIAL;
        if ($newLevel <= $current) {
            return ['skipped' => true, 'reason' => 'already at or above'];
        }
        $incident->update(['escalation_level' => $newLevel, 'escalated_at' => now()]);

        $desc = $this->describe($reason);
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_ESCALATION,
            'تصعيد آلي إلى المستوى '.$newLevel.' ('.$this->levelName($newLevel).'): '.$desc,
            ['from_level' => $current, 'to_level' => $newLevel, 'reason' => $reason], 'critical', null);
        Log::warning('[AutoEscalation] escalated', ['incident' => $incident->id, 'from' => $current, 'to' => $newLevel, 'reason' => $reason]);

        $subject = 'تصعيد حالة طارئة '.$incident->incident_code.' — '.$this->levelName($newLevel);
        $body = $incident->getAlertMessage()."\nالسبب: {$desc}\nبدأت: {$incident->triggered_at->format('H:i')}\nالمستوى الحالي: ".$this->levelName($newLevel);
        $this->notifications->notifyEscalation($incident, self::LEVEL_ROLES[$newLevel] ?? [], $subject, $body);
        if ($newLevel >= self::LEVEL_EXTERNAL) {
            $this->notifications->notifyContacts($incident);
        }

        return ['success' => true, 'from_level' => $current, 'to_level' => $newLevel, 'reason' => $reason, 'timestamp' => now()->toISOString()];
    }

    // ── القواعد ──

    protected function minutes(string $key): ?float
    {
        $v = Setting::get($key);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    protected function elapsedMinutes(EmergencyIncident $incident): float
    {
        return abs(now()->diffInSeconds($incident->triggered_at)) / 60;
    }

    protected function shouldEscalateNoAck(EmergencyIncident $incident): bool
    {
        if ($incident->acknowledged_at) return false;
        $m = $this->minutes('emergency.escalation.no_ack_min');
        return $m !== null && $this->elapsedMinutes($incident) > $m && ($incident->escalation_level ?? 1) < self::LEVEL_SUPERVISOR;
    }

    protected function shouldEscalateNoTeamResponse(EmergencyIncident $incident): bool
    {
        $m = $this->minutes('emergency.escalation.no_team_min');
        if ($m === null) return false;
        $responded = $incident->eventLogs()->where('event_type', EmergencyEventLog::TYPE_TEAM_ARRIVED)->exists()
            || $incident->checkIns()->where('person_type', 'team')->where('status', 'safe')->exists();
        if ($responded) return false;
        return $this->elapsedMinutes($incident) > $m && ($incident->escalation_level ?? 1) < self::LEVEL_MANAGER;
    }

    protected function shouldEscalateMissingPersonnel(EmergencyIncident $incident): bool
    {
        $m = $this->minutes('emergency.escalation.missing_min');
        if ($m === null) return false;
        $first = $incident->eventLogs()->where('event_type', EmergencyEventLog::TYPE_PERSON_MISSING)->orderBy('logged_at')->first();
        if (!$first) return false;
        if (!$incident->checkIns()->where('status', 'missing')->exists()) return false;
        return abs(now()->diffInSeconds($first->logged_at)) / 60 > $m && ($incident->escalation_level ?? 1) < self::LEVEL_EXECUTIVE;
    }

    protected function durationTargetLevel(EmergencyIncident $incident): ?int
    {
        $m = $this->minutes('emergency.escalation.duration_min');
        if ($m === null) return null;
        $periods = (int) floor($this->elapsedMinutes($incident) / $m);
        return (int) min(self::LEVEL_EXTERNAL, self::LEVEL_INITIAL + $periods);
    }

    public function levelName(int $level): string
    {
        return match ($level) {
            self::LEVEL_INITIAL => 'الاستجابة الأولية',
            self::LEVEL_SUPERVISOR => 'المنسق والمناوب',
            self::LEVEL_MANAGER => 'مسؤول السلامة والقيادة',
            self::LEVEL_EXECUTIVE => 'الإدارة العليا ولجنة السلامة',
            self::LEVEL_EXTERNAL => 'الجهات الخارجية',
            default => 'غير معروف',
        };
    }

    protected function describe(string $reason): string
    {
        return match ($reason) {
            'no_acknowledgement' => 'لم يُقرّ أحد باستلام الحالة خلال المهلة',
            'no_team_response' => 'لم يصل أحد من الفريق خلال المهلة',
            'missing_personnel' => 'مفقودون بلا تحديث خلال المهلة',
            'extended_duration' => 'استمرار الحالة تجاوز المهلة',
            'critical_severity' => 'الخطورة حرجة — تصعيد فوري للإدارة العليا',
            default => $reason,
        };
    }

    public function getEscalationRules(): array
    {
        $rules = [];
        foreach (self::SETTING_KEYS as $key => $label) {
            $rules[] = ['key' => $key, 'label' => $label, 'minutes' => $this->minutes($key)];
        }
        return $rules;
    }

    /** يُستدعى من المجدول كل دقيقة (emergency:check-escalation). */
    public function processScheduledChecks(): array
    {
        $results = [];
        foreach (EmergencyIncident::where('status', 'active')->get() as $incident) {
            $results[$incident->id] = $this->checkAndEscalate($incident);
        }
        return ['incidents_checked' => count($results), 'results' => $results, 'timestamp' => now()->toISOString()];
    }
}
