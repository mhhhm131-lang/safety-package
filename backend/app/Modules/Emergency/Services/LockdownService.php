<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\Lockdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * الإغلاق الأمني (من OHSMS) — الحالة في جدول lockdowns بدل الكاش (الإصلاح المقرر ٥-٣).
 * أفعال الأنظمة (أبواب/مصاعد/شاشات) كانت في OHSMS تستدعي خدمات Integration (المرحلة ٥)؛ هنا تُسجَّل «deferred»
 * وتُوصل عند نقل إنترنت الأشياء. الإغلاق حالة طارئة من نوع lockdown تسير بآلة الحالة نفسها.
 */
class LockdownService
{
    const LEVEL_SOFT = 'soft';
    const LEVEL_MODIFIED = 'modified';
    const LEVEL_FULL = 'full';
    const LEVEL_SHELTER = 'shelter';

    public function __construct(protected EmergencyService $emergency, protected EmergencyNotificationService $notifications) {}

    public function initiateLockdown(EmergencyBuilding $building, User $by, string $level = self::LEVEL_FULL, array $options = []): Lockdown
    {
        if ($building->activeLockdown()) {
            throw new \RuntimeException('يوجد إغلاق ساري لهذا المبنى');
        }
        Log::critical('[Lockdown] initiating', ['building' => $building->id, 'level' => $level, 'user' => $by->id]);

        return DB::transaction(function () use ($building, $by, $level, $options) {
            $incident = $this->emergency->triggerAlarm(
                $building, EmergencyIncident::TYPE_LOCKDOWN, $by,
                $level === self::LEVEL_FULL ? 'critical' : 'high',
                (bool) ($options['is_drill'] ?? false),
                'إغلاق أمني ('.(Lockdown::LEVELS[$level] ?? $level).')'.(!empty($options['reason']) ? ': '.$options['reason'] : ''),
                $options['place_id'] ?? null,
            );

            $lockdown = Lockdown::create([
                'building_id' => $building->id,
                'incident_id' => $incident->id,
                'level' => $level,
                'state' => 'active',
                'zones' => $options['zones'] ?? null,
                'options' => $options ?: null,
                'results' => $this->deferredSystemActions($level),
                'reason' => $options['reason'] ?? null,
                'initiated_by_id' => $by->id,
                'initiated_at' => now(),
            ]);

            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_LOCKDOWN, 'بدء الإغلاق الأمني — '.$lockdown->getLevelLabel(),
                ['lockdown_id' => $lockdown->id, 'instructions' => $this->getLockdownInstructions($level)], 'critical', $by->id);

            return $lockdown;
        });
    }

    public function liftLockdown(Lockdown $lockdown, User $by, string $reason = ''): Lockdown
    {
        if (!$lockdown->isActive()) {
            throw new \RuntimeException('لا إغلاق ساري');
        }
        return DB::transaction(function () use ($lockdown, $by, $reason) {
            $lockdown->update([
                'state' => 'lifted', 'lifted_by_id' => $by->id, 'lifted_at' => now(), 'lift_reason' => $reason,
                'results' => array_merge($lockdown->results ?? [], ['lifted' => 'deferred_phase_5']),
            ]);
            if ($lockdown->incident && $lockdown->incident->isOpen()) {
                EmergencyEventLog::log($lockdown->incident, EmergencyEventLog::TYPE_LOCKDOWN, 'رفع الإغلاق الأمني'.($reason ? ' — '.$reason : ''), [], 'info', $by->id);
                $this->emergency->endIncident($lockdown->incident, $by, 'رُفع الإغلاق الأمني. '.$reason);
            }
            return $lockdown->fresh();
        });
    }

    /** إغلاق منطقة (أماكن محددة) بلا حالة طارئة كاملة. */
    public function lockdownZone(EmergencyBuilding $building, array $zones, User $by, array $options = []): Lockdown
    {
        $lockdown = Lockdown::create([
            'building_id' => $building->id, 'level' => 'zone', 'state' => 'partial', 'zones' => $zones,
            'options' => $options ?: null, 'results' => ['zones' => array_fill_keys($zones, 'deferred_phase_5')],
            'reason' => $options['reason'] ?? null, 'initiated_by_id' => $by->id, 'initiated_at' => now(),
        ]);
        Log::warning('[Lockdown] zone lockdown', ['building' => $building->id, 'zones' => $zones, 'user' => $by->id]);
        return $lockdown;
    }

    public function getLockdownStatus(EmergencyBuilding $building): array
    {
        $l = $building->activeLockdown() ?? $building->lockdowns()->latest('initiated_at')->first();
        if (!$l) {
            return ['building_id' => $building->id, 'is_locked_down' => false, 'state' => null];
        }
        return [
            'building_id' => $building->id, 'is_locked_down' => $l->isActive(), 'state' => $l->state, 'level' => $l->level,
            'level_label' => $l->getLevelLabel(), 'initiated_at' => $l->initiated_at?->toISOString(), 'lifted_at' => $l->lifted_at?->toISOString(),
            'incident_id' => $l->incident_id, 'zones' => $l->zones, 'instructions' => $this->getLockdownInstructions($l->level),
        ];
    }

    /** ما كان OHSMS يفعله في الأنظمة لكل مستوى — يُسجَّل مؤجلاً حتى المرحلة ٥. */
    protected function deferredSystemActions(string $level): array
    {
        $actions = match ($level) {
            self::LEVEL_FULL => ['access_control' => 'lock_all', 'elevators' => 'fire_recall', 'signage' => 'lockdown_alert'],
            self::LEVEL_MODIFIED => ['access_control' => 'lock_exterior', 'elevators' => 'normal', 'signage' => 'warning'],
            self::LEVEL_SOFT => ['access_control' => 'lock_exterior', 'elevators' => 'normal', 'signage' => 'info'],
            self::LEVEL_SHELTER => ['access_control' => 'lock_exterior_no_egress', 'elevators' => 'hold', 'signage' => 'shelter'],
            default => [],
        };
        return ['planned' => $actions, 'executed' => 'deferred_phase_5'];
    }

    public function getLockdownInstructions(string $level): array
    {
        return match ($level) {
            self::LEVEL_FULL => ['ابقَ في موقعك الحالي', 'أقفل جميع الأبواب', 'ابتعد عن النوافذ', 'أطفئ الأنوار', 'اكتم الهواتف', 'انتظر التعليمات'],
            self::LEVEL_MODIFIED => ['المحيط مؤمَّن', 'قلّل التنقل غير الضروري', 'ابقَ في منطقة عملك', 'انتظر التعليمات'],
            self::LEVEL_SOFT => ['محيط المبنى مؤمَّن', 'يمكن متابعة الأنشطة العادية', 'كن يقظاً'],
            self::LEVEL_SHELTER => ['انتقل إلى الغرف الداخلية', 'أغلق النوافذ والأبواب', 'سُدّ الفجوات إن أمكن', 'لا تخرج من المبنى', 'انتظر إشارة الأمان'],
            default => ['اتبع تعليمات فريق الطوارئ'],
        };
    }
}
