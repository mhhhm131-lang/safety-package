<?php

namespace App\Modules\Incident\Services;

use App\Core\Services\AuditLogService;
use App\Core\Services\NotificationService;
use App\Core\StateMachine\Exceptions\TransitionException;
use App\Modules\Emergency\Services\PlaceProfile;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentAttachment;
use App\Modules\Incident\Models\IncidentEvent;
use App\Modules\Incident\StateMachines\IncidentStateMachine;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * خدمة بلاغ الشاغل (من OHSMS بلا tenant). ثلاث طبقات تحقق في كل انتقال: الحافة، الدور، الشخص المعيَّن.
 *
 * إضافات المعهد:
 *  - المكان: المبلّغ يختار خطراً من السجل العام؛ إن كان له خطر فعلي في المكان استُعمل للتوجيه (منسقه وفنيه)،
 *    وإلا استُعمل منسق المكان وفنيه من ملفات المستخدمين (user_profiles.place_id). إن لم يوجد فني توقف
 *    التوجيه عند «وصل المركز» ليحيله المركز يدوياً (BACKEND.md ٥-٢-ب: الإحالة للفني مباشرة من المركز).
 *  - رمز تتبع لكل بلاغ عام (لا للسري فقط): «المبلّغ يرى الخط الزمني نفسه بالرمز أو بحسابه».
 *  - المهلة (البند ج): تُحسب من الإعدادات إن قُررت، وتُعاد عند الإحالة.
 */
class IncidentService
{
    public const BOT_USER_ID = 0;

    private const STATUS_TIMESTAMP_MAP = [
        'received' => 'received_at', 'referred' => 'referred_at', 'ref_received' => 'ref_received_at',
        'forwarded' => 'forwarded_at', 'field_received' => 'field_received_at', 'in_progress' => 'in_progress_at',
        'resolved' => 'resolved_at', 'escalated_to_coord' => 'escalated_at', 'escalated_to_manager' => 'escalated_at',
        'closed' => 'closed_at',
    ];

    private const ASSIGNED_USER_TRANSITIONS = [
        'ref_received' => 'incident_coordinator_id', 'forwarded' => 'incident_coordinator_id',
        'field_received' => 'incident_field_team_id', 'in_progress' => 'incident_field_team_id', 'resolved' => 'incident_field_team_id',
    ];

    protected IncidentStateMachine $stateMachine;

    public function __construct(protected NotificationService $notificationService, protected AuditLogService $auditLogService)
    {
        $this->stateMachine = new IncidentStateMachine();
    }

    // ── الإنشاء ──

    /**
     * بلاغ عادي أو عاجل. $data: title?, description, risk_id (مرجعي أو فعلي), place_id?, organization_unit_id?,
     * location_text?, reporter_name?, reporter_phone?, photo (data URL)?
     */
    public function createIncident(string $type, ?int $userId, array $data): Incident
    {
        if (!in_array($type, ['normal', 'urgent'], true)) {
            throw new InvalidArgumentException("نوع البلاغ غير معروف: $type");
        }
        // المرحلة ١١-١ (أ، قرار ٣٤): بلا خطر ← التوجيه بالمكان وحده (كمسار السري)؛ التصنيف عمل المركز من صفحة البلاغ
        $riskId = !empty($data['risk_id']) ? (int) $data['risk_id'] : null;
        $unitId = $this->concernedUnit($userId, $data); // ٢١-٤ (قرار ٥٤): الإدارة المعنية — منها سجلها الفعلي ومنسقها ومعالجها
        $routing = $riskId ? $this->resolveRouting($riskId, $data['place_id'] ?? null, $unitId)
            : $this->noRouting('بلا خطر — يصنّفه المركز');
        $ctx = $this->buildRiskContext($routing['risk_id'], $type);

        $incident = DB::transaction(function () use ($type, $userId, $data, $routing, $ctx, $unitId) {
            $incident = Incident::create([
                'title' => $this->deriveTitle($data, $ctx),
                'description' => $data['description'] ?? '',
                'incident_type' => $type,
                'status' => 'new',
                'organization_unit_id' => $unitId,
                'place_id' => $data['place_id'] ?? null,
                'place_unit_id' => $data['place_unit_id'] ?? null, // ١٨-٣ (ج)
                'location_text' => $data['location_text'] ?? null,
                'actor_id' => $userId,
                'reporter_name' => $userId ? null : ($data['reporter_name'] ?? null),
                'reporter_phone' => $userId ? null : ($data['reporter_phone'] ?? null),
                'risk_id' => $routing['risk_id'],
                'risk_reference_id' => $routing['reference_id'],
                'corrective_action' => $ctx['corrective_action'],
                'incident_coordinator_id' => $routing['coordinator_id'],
                'incident_field_team_id' => $routing['field_team_id'],
                'secret_tracking_code' => $userId ? null : $this->trackingCode(),
                'deadline_at' => $this->deadlineFor($type),
            ]);
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'create', 'to_status' => 'new', 'actor_id' => $userId,
                'note' => $routing['note']]);
            $this->attachPhoto($incident, $data['photo'] ?? null, 'report', $userId);
            return $incident;
        });

        $incident = $this->fastForwardRouting($incident);

        $this->notifyOnCreate($incident, $ctx, $type === 'urgent');
        $this->notifyRiskNotActivated($incident, $routing);
        $this->auditLogService->log(null, 'create', 'Incident', $incident->id, "Created {$type} incident {$incident->code}: {$incident->title}", $userId);
        return $incident;
    }

    /** بلاغ سري: بلا فاعل، بلا سجل تدقيق (حماية الهوية). الخطر اختياري ويربطه المركز لاحقاً. */
    public function createSecretIncident(array $data): array
    {
        $secretKey = (string) Str::uuid();
        $trackingCode = $this->trackingCode();
        $riskId = !empty($data['risk_id']) ? (int) $data['risk_id'] : null;
        $unitId = $this->concernedUnit(null, $data); // ٢١-٤: السري بلا فاعل — إدارة المكان المعني وحدها
        $routing = $riskId ? $this->resolveRouting($riskId, $data['place_id'] ?? null, $unitId) : $this->noRouting('بلا خطر');
        $ctx = $this->buildRiskContext($routing['risk_id'] ?? null);

        $incident = DB::transaction(function () use ($data, $secretKey, $trackingCode, $routing, $ctx, $unitId) {
            $incident = Incident::create([
                'title' => $this->deriveTitle($data, $ctx),
                'description' => $data['description'] ?? '',
                'incident_type' => 'secret',
                'status' => 'new',
                'organization_unit_id' => $unitId,
                'place_id' => $data['place_id'] ?? null,
                'place_unit_id' => $data['place_unit_id'] ?? null, // ١٨-٣ (ج)
                'location_text' => $data['location_text'] ?? null,
                'secret_key' => $secretKey,
                'secret_tracking_code' => $trackingCode,
                'risk_id' => $routing['risk_id'] ?? null,
                'risk_reference_id' => $routing['reference_id'] ?? null,
                'corrective_action' => $ctx['corrective_action'],
                'incident_coordinator_id' => $routing['coordinator_id'] ?? null,
                'incident_field_team_id' => $routing['field_team_id'] ?? null,
                'deadline_at' => $this->deadlineFor('secret'),
            ]);
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'create', 'to_status' => 'new',
                'note' => trim(($data['secrecy_reason'] ?? '').' '.$routing['note']) ?: null]);
            $this->attachPhoto($incident, $data['photo'] ?? null, 'report', null);
            return $incident;
        });

        $incident = $this->fastForwardRouting($incident);
        $this->notifyOnCreate($incident, $ctx, false);
        $this->notifyRiskNotActivated($incident, $routing);

        return ['incident' => $incident, 'secret_key' => $secretKey];
    }

    // ── الانتقالات ──

    public function transition(Incident $incident, int $userId, string $action, string $toStatus, ?string $note = null): Incident
    {
        $fromStatus = $incident->status;
        $this->stateMachine->validate($fromStatus, $toStatus);
        $isBot = $userId === self::BOT_USER_ID;

        if (!$isBot) {
            $role = $this->resolveUserRole($userId);
            $allowedRoles = $this->stateMachine->getAllowedRoles($fromStatus, $toStatus);
            if (!in_array($role, $allowedRoles, true)) {
                throw new TransitionException("الدور «".\App\Core\Permissions\PermissionRegistry::getRoleDisplayName($role)."» لا يملك الانتقال من «".(Incident::STATUS_LABELS[$fromStatus] ?? $fromStatus)."» إلى «".(Incident::STATUS_LABELS[$toStatus] ?? $toStatus)."».");
            }
            // تولّي المعالجة بعد التصعيد أو إعادتها بعد رفض الإغلاق ليس انتقال الفني المعيَّن (كان يسقط في OHSMS)
            $takeover = in_array($fromStatus, ['escalated_to_coord', 'escalated_to_manager', 'resolved'], true);
            if (isset(self::ASSIGNED_USER_TRANSITIONS[$toStatus]) && !$takeover) {
                $col = self::ASSIGNED_USER_TRANSITIONS[$toStatus];
                $expected = $incident->{$col};
                if ($expected && $expected !== $userId) {
                    throw new TransitionException('هذا الانتقال للشخص المعيَّن على البلاغ وحده.');
                }
            }
        }

        $incident->status = $toStatus;
        if (isset(self::STATUS_TIMESTAMP_MAP[$toStatus])) {
            $incident->{self::STATUS_TIMESTAMP_MAP[$toStatus]} = now();
        }
        $incident->save();

        IncidentEvent::create(['incident_id' => $incident->id, 'action' => $action, 'from_status' => $fromStatus,
            'to_status' => $toStatus, 'note' => $note, 'actor_id' => $isBot ? null : $userId]);

        return $incident;
    }

    private function resolveUserRole(int $userId): string
    {
        return UserProfile::where('user_id', $userId)->value('role') ?? 'unknown';
    }

    /**
     * المسار الآلي فور الإنشاء: يتوقف عند أول خطوة تحتاج معيَّناً غير معروف.
     * المرحلة ١٠-٣ (ح-١، كلمة المستخدم ٢٠٢٦-٠٩-١٠): «استلمه الفني» لا يسجّلها النظام آلياً بل الفني بضغطة —
     * فتصح «فجوة البلاغ» (من الإرسال إلى استلام الفني) بدل أن تكون صفراً دائماً.
     */
    public function fastForwardRouting(Incident $incident): Incident
    {
        if ($incident->status !== 'new') return $incident;
        DB::transaction(function () use ($incident) {
            $this->transition($incident, self::BOT_USER_ID, 'receive', 'received', 'مسار تلقائي عند الإنشاء');
            // المعهد: لا إحالة آلية بلا معالج مسمّى — يبقى «وصل المركز» حتى يحيله المركز
            if (empty($incident->incident_field_team_id)) return;
            foreach ($this->stepsToHandler($incident) as [$action, $to, $note]) {
                $this->transition($incident, self::BOT_USER_ID, $action, $to, $note);
            }
        });
        return $incident->fresh();
    }

    /**
     * المعهد: المركز يحيل إلى فني (وقد يسمّي منسقاً). يثبّت المعيَّنين ثم يمرّر المسار الآلي حتى «حُوّل للفني».
     * تُعاد المهلة عند الإحالة (البند ج). يُرسل إشعار للفني والمنسق.
     */
    public function referToField(Incident $incident, int $userId, int $fieldWorkerId, ?int $coordinatorId = null, ?string $note = null): Incident
    {
        if (in_array($incident->status, Incident::TERMINAL, true)) {
            throw new InvalidArgumentException('لا تُحال بلاغات مغلقة أو خارج النطاق.');
        }
        $fw = UserProfile::where('user_id', $fieldWorkerId)->where('is_active', true)->first();
        if (!$fw || !\App\Core\Permissions\PermissionRegistry::isTech($fw->role)) { // ٢٠-٣: فني بأي تخصص
            throw new InvalidArgumentException('المُحال إليه يجب أن يكون فنياً منفّذاً مفعَّلاً.');
        }
        return DB::transaction(function () use ($incident, $userId, $fieldWorkerId, $coordinatorId, $note) {
            $incident->incident_field_team_id = $fieldWorkerId;
            if ($coordinatorId) $incident->incident_coordinator_id = $coordinatorId;
            $incident->assigned_to_id = $fieldWorkerId;
            $incident->assigned_by_id = $userId;
            $incident->assigned_at = now();
            if ($deadline = $this->deadlineFor($incident->incident_type)) {
                $incident->deadline_at = $deadline;
                $incident->overdue_at = null;
            }
            $incident->save();
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'assign', 'from_status' => $incident->status,
                'to_status' => $incident->status, 'note' => 'أُحيل إلى الفني '.($fw = \App\Models\User::find($fieldWorkerId)?->name).($note ? ' — '.$note : ''), 'actor_id' => $userId]);

            if ($incident->status === 'new') {
                $this->transition($incident, self::BOT_USER_ID, 'receive', 'received', 'مسار تلقائي');
            }
            foreach ($this->stepsToHandler($incident, 'إحالة من مركز السلامة') as [$action, $to, $stepNote]) {
                if ($this->stateMachine->canTransition($incident->status, $to)) {
                    $this->transition($incident, self::BOT_USER_ID, $action, $to, $stepNote);
                }
            }
            $this->notifyUser($fieldWorkerId, 'incident.forwarded', 'بلاغ شاغل حُوّل إليك: '.$incident->code,
                $incident->title.' — '.($incident->place?->name ?? 'بلا مكان').($note ? ' — '.$note : ''), "/app/incidents/{$incident->id}");
            if ($incident->incident_coordinator_id) {
                $this->notifyUser($incident->incident_coordinator_id, 'incident.referred', 'بلاغ شاغل أُحيل في نطاقك: '.$incident->code, $incident->title, "/app/incidents/{$incident->id}");
            }
            return $incident->fresh();
        });
    }

    public function fieldReceive(Incident $incident, int $userId): Incident
    {
        return $this->transition($incident, $userId, 'field_receive', 'field_received');
    }

    /**
     * المرحلة ١١-١ (ب، قرار ٣٤ — يعكس ح-١): فتح الفني المعيَّن للبلاغ = استلامه، فلا يُسأل عمّا يعرفه النظام.
     * وقت أول فتح يُسجَّل في `field_opened_at` فتبقى «فجوة البلاغ» (الإحالة ← الفتح) مقيسة كما كانت بالزر.
     */
    public function fieldOpened(Incident $incident, int $userId): Incident
    {
        if ($incident->incident_field_team_id !== $userId) return $incident;
        if (!$incident->field_opened_at) {
            $incident->field_opened_at = now();
            $incident->save();
        }
        if ($incident->status === 'forwarded') {
            try {
                $incident = $this->transition($incident, $userId, 'field_receive', 'field_received', 'سُجّل الاستلام بفتح البلاغ');
            } catch (TransitionException $e) {
                // ليس دوره الاستلام (مثلاً منسق تولّى المعالجة): يبقى كما هو
            }
        }
        return $incident;
    }

    public function beginWork(Incident $incident, int $userId): Incident
    {
        return $this->transition($incident, $userId, 'begin_work', 'in_progress');
    }

    /** الربط العكسي: الفني فتح على البلاغ بلاغَ فحص في نموذج المكان {key,row}. */
    public function linkInspection(Incident $incident, ?int $userId, array $ref): Incident
    {
        $incident->inspection_ref = ['key' => (string) ($ref['key'] ?? ''), 'row' => (string) ($ref['row'] ?? '')];
        $incident->save();
        IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'inspection_linked', 'from_status' => $incident->status,
            'to_status' => $incident->status, 'note' => 'بلاغ الفحص '.$incident->inspection_ref['row'].' في '.$incident->inspection_ref['key'], 'actor_id' => $userId]);
        // «استلمه الفني → جارٍ (يفتح عليه بلاغ فحص)» — إن كان الكاتب هو الفني المعيَّن
        if ($userId && in_array($incident->status, ['forwarded', 'field_received'], true)) {
            try {
                if ($incident->status === 'forwarded') $this->transition($incident, $userId, 'field_receive', 'field_received');
                $this->transition($incident, $userId, 'begin_work', 'in_progress', 'فُتح عليه بلاغ فحص');
            } catch (TransitionException $e) {
                // ليس الفني المعيَّن: يبقى الربط مسجَّلاً بلا انتقال
            }
        }
        return $incident->fresh();
    }

    public function addNote(Incident $incident, int $userId, string $note): IncidentEvent
    {
        return IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'note', 'from_status' => $incident->status,
            'to_status' => $incident->status, 'note' => $note, 'actor_id' => $userId]);
    }

    /**
     * عولج: ملخص ≥ ٣٠ حرفاً ومرفق دليل واحد على الأقل (من OHSMS).
     * المرحلة ١١-١ (ج، قرار ٣٤): الدليل يأتي في الطلب نفسه (`$evidence` = mime/binary/name)، ومن «استلمه الفني» يُسجَّل البدء آلياً.
     */
    public function resolve(Incident $incident, int $userId, string $summary, ?array $evidence = null): Incident
    {
        $summary = trim($summary);
        if (mb_strlen($summary) < 30) {
            throw new InvalidArgumentException('ملخص المعالجة يجب أن يكون ٣٠ حرفاً على الأقل لإثبات تنفيذها فعلياً.');
        }
        if ($evidence) {
            $this->addAttachment($incident, $userId, 'evidence', $evidence['mime'], $evidence['binary'], $evidence['name'] ?? null);
            $incident->refresh();
        }
        if ($incident->attachments()->where('kind', 'evidence')->count() === 0) {
            throw new InvalidArgumentException('أرفق صورة بعد المعالجة (أو مستنداً) دليلاً على إنجازها في الميدان.');
        }
        if ($incident->status === 'field_received' && $incident->incident_field_team_id === $userId) {
            $incident = $this->transition($incident, $userId, 'begin_work', 'in_progress', 'بدأت المعالجة عند تسجيل «عولج»');
        }
        $incident->resolution_summary = $summary;
        $incident->save();
        $incident = $this->transition($incident, $userId, 'resolve', 'resolved', $summary);
        $this->notificationService->notifyRoles(['system_admin', 'system_staff'], 'incident.resolved', 'عولج بلاغ شاغل: '.$incident->code,
            $incident->title.' — بانتظار الإغلاق', "/app/incidents/{$incident->id}");
        if ($incident->actor_id) {
            $this->notifyUser($incident->actor_id, 'incident.resolved', 'عولج بلاغك '.$incident->code, $summary, "/app/incidents/{$incident->id}");
        }
        return $incident;
    }

    public function escalateToCoordinator(Incident $incident, int $userId, string $reason): Incident
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('سبب التصعيد مطلوب (١٠ أحرف على الأقل) لتوثيق سبب تعذّر المعالجة.');
        }
        $incident->escalation_reason = $reason;
        $incident->escalation_level = 1;
        $incident->save();
        $incident = $this->transition($incident, $userId, 'escalate_to_coord', 'escalated_to_coord', $reason);
        $targets = $incident->incident_coordinator_id ? [$incident->incident_coordinator_id] : [];
        foreach ($targets as $t) {
            $this->notifyUser($t, 'incident.escalated', 'صُعّد إليك بلاغ: '.$incident->code, $reason, "/app/incidents/{$incident->id}");
        }
        if (!$targets) {
            $this->notificationService->notifyRoles(['safety_coordinator', 'system_admin', 'system_staff'], 'incident.escalated', 'صُعّد بلاغ بلا منسق: '.$incident->code, $reason, "/app/incidents/{$incident->id}");
        }
        return $incident;
    }

    public function escalateToManager(Incident $incident, int $userId, string $reason): Incident
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('سبب التصعيد للجنة السلامة مطلوب (١٠ أحرف على الأقل).');
        }
        $incident->escalation_reason = $reason;
        $incident->escalation_level = 2;
        $incident->save();
        $incident = $this->transition($incident, $userId, 'escalate_to_manager', 'escalated_to_manager', $reason);
        $this->notifyCommittee('صُعّد بلاغ إلى لجنة السلامة: '.$incident->code, $reason, "/app/incidents/{$incident->id}");
        return $incident;
    }

    /** من تولّى المعالجة بعد التصعيد صار منفّذها: يستطيع أن يعلّم «عولج» (إضافة معهدية: OHSMS كان يقفل المسار هنا). */
    public function resolveEscalation(Incident $incident, int $userId): Incident
    {
        $incident = $this->transition($incident, $userId, 'resolve_escalation', 'in_progress', 'تولّى المعالجة بعد التصعيد');
        $incident->incident_field_team_id = $userId;
        $incident->executor_id = $userId;
        $incident->save();
        return $incident;
    }

    public function outOfScope(Incident $incident, int $userId, ?string $note = null): Incident
    {
        return $this->transition($incident, $userId, 'out_of_scope', 'out_of_scope', $note);
    }

    /** ربط خطر بالبلاغ (للسري بلا خطر، أو إضافة مخاطر أخرى). */
    public function linkRisk(Incident $incident, int $userId, int $riskId): void
    {
        $risk = Risk::find($riskId);
        if (!$risk) throw new InvalidArgumentException("الخطر #{$riskId} غير موجود.");
        $routed = false;
        DB::transaction(function () use ($incident, $userId, $risk, &$routed) {
            if (!$incident->risk_id) {
                // ٢١-٤: تصنيف المركز يوجّه بالقاعدة نفسها — الخطر الفعلي للإدارة المعنية ومنه المنسق والمعالج
                $routing = $this->resolveRouting($risk->id, $incident->place_id, $incident->organization_unit_id);
                $incident->risk_id = $routing['risk_id'];
                $incident->risk_reference_id = $routing['reference_id'];
                if (!$incident->incident_coordinator_id && $routing['coordinator_id']) $incident->incident_coordinator_id = $routing['coordinator_id'];
                if (!$incident->incident_field_team_id && $routing['field_team_id']) {
                    $incident->incident_field_team_id = $routing['field_team_id'];
                    $routed = true;
                }
                $incident->save();
                $this->notifyRiskNotActivated($incident, $routing);
                if ($routing['risk_id'] !== $risk->id) $risk = Risk::find($routing['risk_id']);
            }
            $incident->risks()->syncWithoutDetaching([$risk->id]);
            IncidentEvent::create(['incident_id' => $incident->id, 'action' => 'note', 'from_status' => $incident->status,
                'to_status' => $incident->status, 'note' => 'رُبط بالخطر '.$risk->code.' — '.$risk->title, 'actor_id' => $userId]);
            if ($routed && $incident->status === 'received') {
                foreach ($this->stepsToHandler($incident, 'وُجّه بعد تصنيف المركز') as [$action, $to, $note]) {
                    $this->transition($incident, self::BOT_USER_ID, $action, $to, $note);
                }
            }
        });
        if ($routed) {
            $this->notifyUser($incident->incident_field_team_id, 'incident.forwarded', 'بلاغ شاغل حُوّل إليك: '.$incident->code, $incident->title, "/app/incidents/{$incident->id}");
            if ($incident->incident_coordinator_id) $this->notifyUser($incident->incident_coordinator_id, 'incident.referred', 'بلاغ شاغل في نطاقك: '.$incident->code, $incident->title, "/app/incidents/{$incident->id}");
        }
    }

    public function updateActions(Incident $incident, array $data): void
    {
        $incident->update(['corrective_action' => $data['corrective_action'] ?? null, 'preventive_action' => $data['preventive_action'] ?? null]);
    }

    /** مرفق دليل المعالجة (ملف مرفوع). */
    public function addAttachment(Incident $incident, ?int $userId, string $kind, string $mime, string $binary, ?string $name): IncidentAttachment
    {
        if (!in_array($mime, IncidentAttachment::MIMES, true)) {
            throw new InvalidArgumentException('نوع الملف غير مقبول (صورة JPG/PNG/WebP أو PDF).');
        }
        if (strlen($binary) > IncidentAttachment::MAX_BYTES) {
            throw new InvalidArgumentException('حجم الملف أكبر من ٣ ميغابايت.');
        }
        $row = $incident->attachments()->create(['kind' => $kind, 'original_name' => $name, 'mime' => $mime, 'size' => strlen($binary),
            'data' => base64_encode($binary), 'uploaded_by_id' => $userId, 'created_at' => now()]);
        // المرحلة ١١-١ (ج، قرار ٣٤): أول دليل من الفني المعيَّن وهو في «استلمه الفني» = بدأ المعالجة (نمط linkInspection)
        if ($kind === 'evidence' && $userId && $incident->status === 'field_received' && $incident->incident_field_team_id === $userId) {
            try {
                $this->transition($incident, $userId, 'begin_work', 'in_progress', 'بدأت المعالجة بأول دليل مرفوع');
            } catch (TransitionException $e) {
                // ليس دوره: يبقى المرفق مسجَّلاً بلا انتقال
            }
        }
        return $row;
    }

    // ── اللوحة والتفاصيل ──

    public function getDashboardData(int $userId, IncidentVisibilityService $visibility): array
    {
        $counts = $visibility->getVisibleIncidents($userId)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $open = 0;
        foreach ($counts as $s => $c) if (!in_array($s, Incident::TERMINAL, true)) $open += $c;
        return [
            'total' => array_sum($counts),
            'open' => $open,
            'waiting_center' => ($counts['new'] ?? 0) + ($counts['received'] ?? 0),
            'with_field' => ($counts['forwarded'] ?? 0) + ($counts['field_received'] ?? 0) + ($counts['in_progress'] ?? 0),
            'escalated' => ($counts['escalated_to_coord'] ?? 0) + ($counts['escalated_to_manager'] ?? 0),
            'resolved' => $counts['resolved'] ?? 0,
            'closed' => ($counts['closed'] ?? 0) + ($counts['out_of_scope'] ?? 0),
            'overdue' => $visibility->getVisibleIncidents($userId)->whereIn('status', Incident::BEFORE_FIELD)->whereNotNull('deadline_at')->where('deadline_at', '<', now())->count(),
        ];
    }

    public function getDetail(int $incidentId): ?Incident
    {
        return Incident::where('id', $incidentId)->with([
            'events' => fn ($q) => $q->with('actor')->latest('id'),
            'risks.category', 'risks.subCategory', 'attachments', 'organizationUnit', 'place', 'actor', 'assignedTo',
            'incidentCoordinator', 'incidentFieldTeam', 'coordVerifiedBy', 'risk.category', 'risk.subCategory', 'riskReference',
        ])->first();
    }

    // ── إشعارات ──

    public function notifyUser(?int $userId, string $type, string $title, ?string $message, ?string $url): void
    {
        if ($userId) $this->notificationService->create($userId, $type, $title, $message, $url);
    }

    /** لجنة السلامة، وحتى تشكيلها مسؤول السلامة (قرار BACKEND.md ٥-٢-ب ج). */
    public function notifyCommittee(string $title, ?string $message, ?string $url): void
    {
        $n = $this->notificationService->notifyRoles(['safety_committee'], 'incident.committee', $title, $message, $url);
        if ($n === 0) {
            $this->notificationService->notifyRoles(['system_admin'], 'incident.committee', $title, $message, $url);
        }
    }

    private function notifyOnCreate(Incident $incident, array $ctx, bool $urgent): void
    {
        $title = ($urgent ? '🚨 بلاغ عاجل: ' : 'بلاغ شاغل جديد: ').$incident->code;
        $msg = $incident->title.' — '.($incident->place?->name ?? 'بلا مكان').($ctx['risk_title'] ? ' (الخطر: '.$ctx['risk_title'].')' : '');
        $url = "/app/incidents/{$incident->id}";
        // المركز دائماً
        $this->notificationService->notifyRoles(array_unique(array_merge(['system_admin', 'system_staff'], $ctx['notify_roles'])), 'incident.new', $title, $msg, $url);
        // المعيَّنون
        foreach (array_unique(array_filter([$incident->incident_coordinator_id, $incident->incident_field_team_id])) as $uid) {
            $this->notifyUser($uid, 'incident.forwarded', ($urgent ? '🚨 ' : '').'بلاغ حُوّل إليك: '.$incident->code, $msg, $url);
        }
    }

    // ── التوجيه ──

    /**
     * ٢١-٤ (قرار ٥٤): الإدارة المعنية بالبلاغ — وحدة المكان إن حُددت وكانت لإدارة؛ وإلا الإدارة المشغّلة للمكان (ملف المكان)؛
     * وفي المكاتب الإدارية، حيث الأماكن هي الإدارات نفسها، إدارة المبلّغ من حسابه. بلا أيٍّ منها: إدارة المبلّغ، أو لا شيء للضيف.
     */
    private function concernedUnit(?int $userId, array $data): ?int
    {
        if (!empty($data['organization_unit_id'])) return (int) $data['organization_unit_id'];
        if (!empty($data['place_unit_id']) && ($u = PlaceUnit::find($data['place_unit_id'])?->organization_unit_id)) return (int) $u;
        $own = $userId ? UserProfile::where('user_id', $userId)->value('organization_unit_id') : null;
        $place = !empty($data['place_id']) ? Place::find($data['place_id']) : null;
        if ($place && $place->code !== PlaceProfile::HUB) {
            $list = PlaceProfile::unitList($place->code, PlaceProfile::get($place->code));
            $code = (string) ($list[0]['dept'] ?? '');
            if ($code !== '' && ($op = OrganizationUnit::where('code', $code)->value('id'))) return (int) $op;
        }
        return $own ? (int) $own : null;
    }

    /**
     * من خطر اختاره المبلّغ (مرجعي غالباً) إلى الخطر الفعلي **للإدارة المعنية** (هي ثم ما فوقها في الهيكل)، ومنه المنسق والمعالج.
     * ٢١-٤: كان يطابق بالمكان وحده فيأخذ آخر خطر فعلي أُنشئ فيه لأي إدارة (ع١). لا خطر فعلي للإدارة ← المركز، ويُنبَّه مديرها.
     */
    private function resolveRouting(int $riskId, ?int $placeId, ?int $unitId): array
    {
        $risk = Risk::find($riskId);
        if (!$risk) throw new InvalidArgumentException('الخطر المختار غير موجود.');
        $referenceId = $risk->risk_type === 'reference' ? $risk->id : ($risk->parent_reference_id ?: null);
        $routingRisk = $risk->risk_type === 'active' ? $risk : null;
        if (!$routingRisk && $unitId) {
            foreach ($this->unitAndAncestors($unitId) as $uid) {
                $q = Risk::where('risk_type', 'active')->where('parent_reference_id', $risk->id)->where('organization_unit_id', $uid);
                // خطر الإدارة في هذا المكان أولاً، ثم خطرها بلا مكان، ثم أي خطر لها
                $routingRisk = (clone $q)->where('place_id', $placeId)->orderByDesc('id')->first()
                    ?? (clone $q)->whereNull('place_id')->orderByDesc('id')->first()
                    ?? $q->orderByDesc('id')->first();
                if ($routingRisk) break;
            }
        }
        if (!$routingRisk) {
            $r = $this->noRouting('لا خطر فعلي لهذا الخطر في سجل الإدارة المعنية — بانتظار إحالة المركز');
            $r['risk_id'] = $risk->id;
            $r['reference_id'] = $referenceId;
            $r['not_activated'] = true;
            return $r;
        }
        $notes = array_filter([
            $routingRisk->id !== $risk->id ? 'وُجّه عبر الخطر الفعلي '.$routingRisk->code : null,
            $routingRisk->assigned_field_team_id ? null : 'الخطر الفعلي بلا معالج مسمّى — بانتظار إحالة المركز',
        ]);
        return ['coordinator_id' => $routingRisk->assigned_coordinator_id, 'field_team_id' => $routingRisk->assigned_field_team_id,
            'note' => implode('؛ ', $notes) ?: null, 'risk_id' => $routingRisk->id, 'reference_id' => $referenceId];
    }

    /** لا معيَّن: يبقى البلاغ «وصل المركز». لا قفز إلى «فني المكان» (ع٥). */
    private function noRouting(string $note): array
    {
        return ['coordinator_id' => null, 'field_team_id' => null, 'note' => $note, 'risk_id' => null, 'reference_id' => null];
    }

    /** الوحدة ثم آباؤها حتى الجذر */
    private function unitAndAncestors(int $unitId): array
    {
        $out = [];
        for ($u = OrganizationUnit::find($unitId), $n = 0; $u && $n < 10; $u = $u->parent_id ? OrganizationUnit::find($u->parent_id) : null, $n++) {
            $out[] = $u->id;
        }
        return $out;
    }

    /** خطوات النظام من «وصل المركز» إلى «حُوّل للمعالج»: بمنسق مسمّى تمر بخطوتيه ويُشعَر؛ وبلا منسق تحويل مباشر لا يُنسب إلى أحد. */
    private function stepsToHandler(Incident $incident, string $note = 'مسار تلقائي عند الإنشاء'): array
    {
        if (empty($incident->incident_coordinator_id)) {
            return [['forward', 'forwarded', $note.' — لا منسق مسمّى: تحويل مباشر إلى المعالج']];
        }
        return [['refer', 'referred', $note], ['ref_receive', 'ref_received', $note.' — أُشعر المنسق'], ['forward', 'forwarded', $note]];
    }

    /** ٢١-٤: خطر اختاره المبلّغ ولم تفعّله الإدارة المعنية ← مديرها ومنسق سلامتها يُنبَّهان ليفعّلاه ويسمّيا منسقه ومعالجه */
    private function notifyRiskNotActivated(Incident $incident, array $routing): void
    {
        if (empty($routing['not_activated']) || !$incident->organization_unit_id) return;
        $risk = Risk::find($routing['risk_id']);
        $ids = UserProfile::where('organization_unit_id', $incident->organization_unit_id)->where('is_active', true)
            ->whereIn('role', ['department_manager', 'section_manager', 'branch_manager', 'safety_coordinator'])->pluck('user_id');
        foreach ($ids as $uid) {
            $this->notifyUser($uid, 'incident.risk_not_activated', 'بلاغ على خطر لم تفعّله إدارتك: '.$incident->code,
                'فعّل «'.($risk?->title ?? 'الخطر').'» في السجل الفعلي لإدارتك وسمِّ منسقه ومعالجه — البلاغ الآن عند مركز السلامة.',
                $risk ? "/app/risk/{$risk->id}/activate" : "/app/incidents/{$incident->id}");
        }
    }

    /**
     * الإجراء التصحيحي المنسوخ إلى البلاغ يأتي من الطبقة المناسبة لنوعه (قاعدة الجداول ٠٠-و، ٢٠٢٦-٠٩-١١):
     * عادي/سري = انحراف تشغيلي ← التشغيلية؛ عاجل ← الاستجابة؛ وإن خلت الطبقة يُؤخذ من الاستباقية (بيانات OHSMS القديمة).
     */
    private function buildRiskContext(?int $riskId, string $type = 'normal'): array
    {
        $result = ['corrective_action' => null, 'notify_roles' => [], 'risk_title' => null];
        if (!$riskId) return $result;
        $risk = Risk::with(['phases.affectedGroupDetails'])->find($riskId);
        if (!$risk) return $result;
        $key = $type === 'urgent' ? RiskPhase::PHASE_RESPONSE : RiskPhase::PHASE_OPERATIONAL;
        $proactive = $risk->phases->firstWhere('phase', RiskPhase::PHASE_PROACTIVE);
        $result['corrective_action'] = $risk->phases->firstWhere('phase', $key)?->corrective_action ?: $proactive?->corrective_action;
        $result['risk_title'] = $risk->title;
        $impactRoles = ['critical' => ['top_management', 'safety_committee', 'safety_coordinator'], 'high' => ['safety_committee', 'safety_coordinator'], 'medium' => ['safety_coordinator'], 'low' => []];
        foreach ($risk->phases as $phase) {
            foreach ($phase->affectedGroupDetails as $d) {
                $result['notify_roles'] = array_values(array_unique(array_merge($result['notify_roles'], $impactRoles[$d->impact] ?? [])));
            }
        }
        return $result;
    }

    private function deriveTitle(array $data, array $ctx): string
    {
        $t = trim((string) ($data['title'] ?? ''));
        if ($t !== '') return mb_substr($t, 0, 200);
        $place = !empty($data['place_id']) ? Place::find($data['place_id'])?->name : null;
        $base = $ctx['risk_title'] ?: mb_substr(trim((string) ($data['description'] ?? 'بلاغ شاغل')), 0, 80);
        return mb_substr($base.($place ? ' — '.$place : ''), 0, 200);
    }

    private function deadlineFor(string $type): ?\Illuminate\Support\Carbon
    {
        $h = Setting::deadlineHours($type);
        return $h ? now()->addMinutes((int) round($h * 60)) : null;
    }

    private function trackingCode(): string
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (Incident::where('secret_tracking_code', $code)->exists());
        return $code;
    }

    private function attachPhoto(Incident $incident, ?string $dataUrl, string $kind, ?int $userId): void
    {
        $row = IncidentAttachment::fromDataUrl($dataUrl, $kind, $userId);
        if ($row) $incident->attachments()->create($row);
    }
}
