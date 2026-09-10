<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyIncidentStep;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\UserProfile;

/**
 * خطوات خطة الاستجابة في الحالة الحية (المرحلة ١٠-٢، المكوّنات ج + د + هـ):
 *   ج) عند التفعيل تُنسخ خطوات خطة المكان (المسار الطبي دائماً + مسار المكان بحسب النوع) إلى emergency_incident_steps
 *      بموعد كل خطوة = التفعيل + حد نافذتها؛ الشرطية بلا موعد. «تم» يسجّل بالثانية ومن، والفارق عن المستهدف.
 *      ما يثبت من السجل يُعلَّم آلياً: وصول عضو الفريق الأولي = «التدخل الأولي»؛ السيطرة = «تقييم»؛ الانتهاء = «استعادة التشغيل».
 *      (نداء الطبيب لا إشارة له في السجل اليوم — يُعلَّم بيد المناوب.)
 *   د) لحظة التفعيل يصل كل صاحب دور خطواته هو (بطاقته من RoleCards ← دوره في النظام أو عضويته في الفريق الأولي).
 *   هـ) الأمر المجدول القائم يفحص المعلّق المتجاوز موعده: سطر أحمر في السجل + تنبيه لصاحبه وللقيادة. النوافذ من الوثيقة، لا أرقام مخترعة.
 */
class IncidentStepsService
{
    /** نوع الحالة ← مسار المكان الذي يُنسخ مع المسار الطبي. الطبي وحده لحالة طبية. */
    public const TYPE_PATHS = [
        'medical' => ['medical'],
        'flood' => ['medical', 'other', 'fire'],   // مركز البيانات: «حالات أخرى» (مياه/تبريد/طاقة)؛ بقية الأماكن مسار المكان
    ];

    public function __construct(protected EmergencyNotificationService $notifications) {}

    /** ج) نسخ الخطوات عند التفعيل. يعيد عدد الخطوات المنسوخة (٠ إن لا خطة للمكان). */
    public function seed(EmergencyIncident $incident): int
    {
        if (!$incident->place_id) return 0;
        $plan = ResponsePlan::where('place_id', $incident->place_id)->with('steps')->first();
        if (!$plan) {
            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_PLAN_STEP, 'لا خطة استجابة مزامَنة لهذا المكان — لا قائمة خطوات', [], 'warning', $incident->triggered_by_id);
            return 0;
        }
        $wanted = self::TYPE_PATHS[$incident->incident_type] ?? ['medical', 'fire'];
        $available = $plan->steps->pluck('path_key')->unique()->all();
        // «other» موجود في مركز البيانات وحده؛ إن غاب فمسار المكان
        $paths = array_values(array_filter($wanted, fn ($p) => in_array($p, $available, true)));
        if ($incident->incident_type === 'flood' && in_array('other', $paths, true)) $paths = array_values(array_diff($paths, ['fire']));

        $n = 0; $counts = [];
        foreach ($plan->steps as $s) {
            if (!in_array($s->path_key, $paths, true)) continue;
            EmergencyIncidentStep::create([
                'incident_id' => $incident->id, 'plan_step_id' => $s->id, 'path_key' => $s->path_key, 'path_title' => $s->path_title,
                'sort' => $s->sort, 'label' => $s->label, 'title' => $s->title, 'when_text' => $s->when_text,
                'window_from_sec' => $s->window_from_sec, 'window_to_sec' => $s->window_to_sec, 'is_conditional' => $s->is_conditional,
                'who_text' => $s->who_text, 'where_text' => $s->where_text, 'how_text' => $s->how_text,
                'role_cards' => $s->role_cards, 'role_card_no' => $s->role_card_no,
                'status' => EmergencyIncidentStep::STATUS_PENDING,
                'due_at' => $s->window_to_sec !== null ? $incident->triggered_at->copy()->addSeconds($s->window_to_sec) : null,
            ]);
            $n++; $counts[$s->path_title] = ($counts[$s->path_title] ?? 0) + 1;
        }
        $detail = implode(' + ', array_map(fn ($t, $c) => "$c $t", array_keys($counts), $counts));
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_PLAN_STEP,
            'قائمة خطوات الخطة: '.$n.' خطوة من وثيقة '.$plan->place->code.' ('.$detail.') — كل خطوة بعدّاد من لحظة التفعيل',
            ['plan_id' => $plan->id, 'fingerprint' => $plan->fingerprint, 'steps' => $n, 'paths' => $paths], 'info', $incident->triggered_by_id);
        return $n;
    }

    /** «تم» بيد المناوب أو صاحب الدور، أو آلياً بمصدر من السجل. */
    public function complete(EmergencyIncidentStep $step, ?User $by, ?string $byName = null, ?string $autoSource = null, ?string $note = null): EmergencyIncidentStep
    {
        if (!$step->isPending()) return $step;
        $incident = $step->incident;
        $now = now();
        $elapsed = (int) abs($now->diffInSeconds($incident->triggered_at));
        $delta = $step->window_to_sec !== null ? $elapsed - $step->window_to_sec : null;
        $step->update([
            'status' => EmergencyIncidentStep::STATUS_DONE, 'done_at' => $now, 'done_by_id' => $by?->id,
            'done_by_name' => $byName ?? $by?->name, 'delta_sec' => $delta, 'auto_source' => $autoSource, 'note' => $note,
        ]);
        $late = $delta !== null && $delta > 0;
        $msg = 'تمت الخطوة '.$step->label.' «'.$step->title.'» بعد '.EmergencyIncidentStep::secs($elapsed).' من التفعيل'
            .($step->window_to_sec !== null ? ' (المستهدف حتى '.EmergencyIncidentStep::secs($step->window_to_sec).($late ? ' — تأخر '.EmergencyIncidentStep::secs($delta) : ' — ضمن النافذة').')' : ' (شرطية)')
            .($autoSource ? ' — آلياً من '.(EmergencyIncidentStep::AUTO_LABELS[$autoSource] ?? $autoSource) : '')
            .($byName && !$by ? ' — '.$byName : '').($note ? ' — '.$note : '');
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_PLAN_STEP, $msg,
            ['step_id' => $step->id, 'elapsed_sec' => $elapsed, 'delta_sec' => $delta, 'auto' => $autoSource], $late ? 'warning' : 'info', $by?->id);
        return $step->fresh();
    }

    public function skip(EmergencyIncidentStep $step, User $by, ?string $note = null): EmergencyIncidentStep
    {
        if (!$step->isPending()) return $step;
        $step->update(['status' => EmergencyIncidentStep::STATUS_SKIPPED, 'done_at' => now(), 'done_by_id' => $by->id, 'done_by_name' => $by->name, 'note' => $note]);
        EmergencyEventLog::log($step->incident, EmergencyEventLog::TYPE_PLAN_STEP, 'تُخطّيت الخطوة '.$step->label.' «'.$step->title.'»'.($note ? ' — '.$note : ''), ['step_id' => $step->id], 'warning', $by->id);
        return $step->fresh();
    }

    /** أتمتة ما يثبت من السجل: تُعلَّم الخطوات المعلّقة التي يطابق عنوانها الإشارة. */
    public function autoComplete(EmergencyIncident $incident, string $source, ?User $by = null, ?string $byName = null): int
    {
        $needle = match ($source) {
            'team_arrived' => ['التدخل الأولي'],
            'contained' => ['تقييم'],
            'ended' => ['استعادة'],
            default => [],
        };
        if (!$needle) return 0;
        $n = 0;
        foreach ($incident->planSteps()->pending()->get() as $step) {
            foreach ($needle as $k) {
                if (str_contains($step->title, $k)) { $this->complete($step, $by, $byName, $source); $n++; break; }
            }
        }
        return $n;
    }

    /** د) تنبيه أصحاب الأدوار بخطواتهم لحظة التفعيل. يعيد عدد المستخدمين المنبَّهين. */
    public function notifyOwners(EmergencyIncident $incident): int
    {
        $steps = $incident->planSteps()->orderBy('sort')->get();
        if ($steps->isEmpty()) return 0;
        $perUser = []; // user_id => [steps]
        $teamMembers = EmergencyTeam::active()->with('members.user')->where('place_id', $incident->place_id)->get()->flatMap->members;
        foreach ($steps as $step) {
            foreach ($step->role_cards ?? [] as $no) {
                $card = RoleCards::get((int) $no);
                if (!$card) continue;
                if (!empty($card['role'])) {
                    foreach (UserProfile::where('role', $card['role'])->where('is_active', true)->pluck('user_id') as $uid) $perUser[$uid][$step->id] = $step;
                }
                if (!empty($card['team'])) {
                    foreach ($teamMembers as $m) {
                        if ($m->user_id && in_array($m->role_key, $card['team'], true)) $perUser[$m->user_id][$step->id] = $step;
                    }
                }
            }
        }
        $place = $incident->place?->name ?? '';
        $users = User::whereIn('id', array_keys($perUser))->get()->keyBy('id');
        $n = 0;
        foreach ($perUser as $uid => $mine) {
            $user = $users[$uid] ?? null;
            if (!$user) continue;
            $lines = [];
            foreach ($mine as $s) {
                $lines[] = $s->label.' '.$s->title.' — '.($s->when_text ?? '').($s->where_text ? ' — '.$s->where_text : '').($s->how_text ? ' — '.$s->how_text : '');
            }
            $subject = 'خطواتك في خطة '.$place.' — '.$incident->incident_code;
            $body = 'الحالة: '.$incident->getTypeLabel().' في '.$place.". خطواتك من خطة الاستجابة وبطاقة دورك:\n".implode("\n", $lines)."\nاضغط «تم» في شاشة الحالة عند إتمام كل خطوة.";
            $this->notifications->notifyUsers($incident, [$user], $subject, $body, 'emergency.plan_step');
            $n++;
        }
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_PLAN_STEP, 'نُبّه أصحاب الأدوار بخطواتهم: '.$n.' حساباً؛ ومن لا حساب له في قائمة النداء الهاتفي مع خطوته', ['users' => $n], 'info', $incident->triggered_by_id);
        return $n;
    }

    /** هـ) الخطوات المتجاوزة موعدها ولم تُعلَّم: سطر أحمر + تنبيه صاحبها والقيادة. يعيد عدد ما نُبّه عليه الآن. */
    public function checkOverdue(EmergencyIncident $incident): int
    {
        if (!$incident->isOpen()) return 0;
        $n = 0;
        $overdue = $incident->planSteps()->pending()->whereNotNull('due_at')->where('due_at', '<', now())->whereNull('overdue_alerted_at')->orderBy('sort')->get();
        foreach ($overdue as $step) {
            $late = (int) abs(now()->diffInSeconds($step->due_at));
            $step->update(['overdue_alerted_at' => now()]);
            $owner = $step->primaryCard();
            $msg = 'تجاوز: الخطوة '.$step->label.' «'.$step->title.'» لم تُعلَّم بعد انقضاء نافذتها ('.($step->when_text ?? '').') — تأخر '.EmergencyIncidentStep::secs($late)
                .($owner ? ' — صاحبها: '.$owner['no'].' '.$owner['name'] : '');
            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_PLAN_STEP, $msg, ['step_id' => $step->id, 'late_sec' => $late], 'critical', null);
            // صاحب الخطوة + القيادة (مسؤول السلامة، المناوب، قائد الطوارئ)
            $roles = ['system_admin', 'system_staff', 'admin_eng_manager'];
            $teamKeys = [];
            foreach ($step->role_cards ?? [] as $no) {
                $c = RoleCards::get((int) $no);
                if (!empty($c['role'])) $roles[] = $c['role'];
                if (!empty($c['team'])) $teamKeys = array_merge($teamKeys, $c['team']);
            }
            $ids = UserProfile::whereIn('role', array_unique($roles))->where('is_active', true)->pluck('user_id')->all();
            if ($teamKeys) {
                $members = EmergencyTeam::active()->with('members')->where('place_id', $incident->place_id)->get()->flatMap->members;
                foreach ($members as $m) if ($m->user_id && in_array($m->role_key, $teamKeys, true)) $ids[] = $m->user_id;
            }
            $users = User::whereIn('id', array_unique($ids))->get();
            $this->notifications->notifyUsers($incident, $users, 'تجاوز خطوة في خطة الاستجابة — '.$incident->incident_code, $msg, 'emergency.step_overdue');
            $n++;
        }
        return $n;
    }

    /** مصفوفة للاستطلاع من شاشة التتبع. */
    public function toArray(EmergencyIncident $incident): array
    {
        return $incident->planSteps()->orderBy('sort')->get()->map(fn (EmergencyIncidentStep $s) => [
            'id' => $s->id, 'path' => $s->path_key, 'label' => $s->label, 'title' => $s->title, 'status' => $s->status,
            'due_at' => $s->due_at?->toIso8601String(), 'done_at' => $s->done_at?->format('H:i:s'), 'delta_sec' => $s->delta_sec,
            'delta_label' => $s->deltaLabel(), 'done_by' => $s->done_by_name, 'auto' => $s->auto_source, 'overdue' => $s->isOverdue(),
        ])->all();
    }
}
