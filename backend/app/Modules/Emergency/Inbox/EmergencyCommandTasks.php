<?php

namespace App\Modules\Emergency\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Models\User;
use App\Modules\Emergency\Models\AfterActionReport;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyMassMessage;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Emergency\Models\Lockdown;
use App\Modules\Emergency\Support\RoleCards;
use Illuminate\Support\Collection;

/**
 * المرحلة ٢٧-أ (قرار ٦٧): ما يُراد من أصحاب القرار وقت الحالة كان في الشاشة الحية وحدها — يصلهم بطاقةً: سؤال وزر، تختفي حين يُفعل.
 *
 *   ٢٤ إقرار الحالة            ← المركز والفريق الأولي للمكان (من له حساب): أول من يضغط يُقرّ فتختفي عن الجميع
 *   ٢٥ السيطرة ثم الإنهاء      ← قائد فريق الطوارئ (بطاقة الدور ١) والمركز
 *   ٢٧ من لم يُحصر والمفقودون  ← المركز
 *   ٢٢ من لم يردّ على الرسالة  ← المركز: تذكير واحد (من بقي يظهر في الحصر)
 *   ٢٨ الإغلاق الأمني الساري    ← المركز (وحالته لا تأخذ بطاقة سيطرة ثانية — رفعه يُنهيها)
 *   ٣٠ تقرير ما بعد الحادث      ← المركز: اعتمده ثم انشره
 *
 * قراءة فقط من الجداول القائمة؛ الأفعال مساراتها القائمة بصلاحياتها. لا مهلة مخترعة ولا حقل جديد.
 * «المركز» = مسؤول السلامة والمناوب (كما في EmergencyTasks). «المنسق يستلم البلاغ ويحوّله» (٣ و٤ في الجرد) ليست هنا:
 * النظام يؤدي الخطوتين وحده ويُشعر المنسق (IncidentService::stepsToHandler) فلا حالة تنتظره.
 */
class EmergencyCommandTasks implements TaskSource
{
    private const CENTER = ['system_admin', 'system_staff'];

    /** بطاقة الدور ١ في بطاقات السلامة الـ٢١: «المشرف العام للسلامة وقائد فريق الطوارئ» */
    private const COMMANDER_CARD = 1;

    public function tasksFor(User $user): Collection
    {
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return collect();
        $role = $user->role();
        $center = in_array($role, self::CENTER, true);
        $commander = ($c = RoleCards::get(self::COMMANDER_CARD)) && !empty($c['role']) && $c['role'] === $role;
        $out = collect();

        // ٢٨-٥ (قرار ٧٨): حالات مباني الحساب — المناوب بمبناه
        $open = EmergencyIncident::open()->with('place')->whereIn('building_id', \App\Modules\Governance\Services\BuildingContext::choices($user)->pluck('id'))->orderBy('triggered_at')->get();
        $locks = Lockdown::whereIn('state', ['active', 'partial'])->with('building')->get();
        $lockedIncidents = $locks->pluck('incident_id')->filter()->all();

        foreach ($open as $inc) {
            $what = $inc->incident_code.' '.$inc->getTypeLabel().($inc->place ? ' في '.$inc->place->name : '');
            $live = route('emergency.incidents.live', $inc);

            // ٢٤ — لم يستلمها أحد بعد
            if (!$inc->acknowledged_at && $user->can('respond', $inc) && ($center || $this->inPlaceTeam($user, $inc))) {
                $out->push(new Task(
                    key: "eack:{$inc->id}", module: 'الطوارئ',
                    question: $what.': لم يستلمها أحد بعد — استلمتَها؟',
                    primary: ['label' => 'استلمتُها', 'url' => route('emergency.incidents.acknowledge', $inc), 'method' => 'POST'],
                    secondary: $center ? ['label' => 'شاشة الحالة', 'url' => $live] : ['label' => 'ماذا أفعل', 'url' => route('emergency.me')],
                    isOverdue: true, place: $inc->place?->name, detailsUrl: $center ? $live : route('emergency.me'), createdAt: $inc->triggered_at,
                ));
            }

            // ٢٥ — السيطرة ثم الإنهاء (حالة الإغلاق الأمني تُنهى برفعه: بطاقتها ٢٨)
            if (($center || $commander) && !in_array($inc->id, $lockedIncidents, true)) {
                if ($inc->status === EmergencyIncident::STATUS_ACTIVE && $user->can('contain', $inc)) {
                    $out->push(new Task(
                        key: "ectl:{$inc->id}", module: 'الطوارئ',
                        question: $what.': نشطة — سُيطر عليها؟',
                        primary: ['label' => 'سُيطر عليها', 'url' => route('emergency.incidents.contain', $inc), 'method' => 'POST'],
                        secondary: ['label' => 'شاشة الحالة', 'url' => $live],
                        isOverdue: true, place: $inc->place?->name, detailsUrl: $live, createdAt: $inc->triggered_at,
                    ));
                } elseif ($inc->status === EmergencyIncident::STATUS_CONTAINED && $user->can('end', $inc)) {
                    // الإنهاء من شاشة الحالة: نافذته تنبّه بمن لم يصل وطلبات المساعدة المفتوحة قبل إعلان الأمان (٢٢-١٥)
                    $out->push(new Task(
                        key: "ectl:{$inc->id}", module: 'الطوارئ',
                        question: $what.': تمت السيطرة — أنهِها وأعلن الأمان',
                        primary: ['label' => 'أنهِها', 'url' => $live.'#endModal'],
                        isOverdue: true, place: $inc->place?->name, detailsUrl: $live, createdAt: $inc->contained_at ?? $inc->triggered_at,
                    ));
                }
            }

            if (!$center) continue;

            // ٢٧ — من لم يسجّل وصوله، والمفقودون (العدّ نفسه الذي تعرضه شاشة الحالة ونافذة الإنهاء: QrMusteringService::getLiveStats)
            $rows = EvacuationCheckIn::where('incident_id', $inc->id);
            $pending = (clone $rows)->where('status', EvacuationCheckIn::STATUS_EVACUATING)->count();
            $missing = (clone $rows)->where('status', EvacuationCheckIn::STATUS_MISSING)->count();
            if ($pending || $missing) {
                // العدد بعد الوصف حتى تصحّ الجملة لأي عدد
                $parts = array_filter([$missing ? 'مفقود: '.$missing : null, $pending ? 'لم يسجّل وصوله: '.$pending : null]);
                $out->push(new Task(
                    key: "emuster:{$inc->id}", module: 'الطوارئ',
                    question: $inc->incident_code.($inc->place ? ' في '.$inc->place->name : '').': '.implode('، ', $parts).' — تحقّق منهم',
                    primary: ['label' => 'شاشة الحصر', 'url' => $live.'#muster'],
                    isOverdue: $missing > 0, place: $inc->place?->name, detailsUrl: $live, createdAt: $inc->triggered_at,
                ));
            }
        }

        if (!$center) return $out;

        // ٢٢ — آخر رسالة جماعية لكل حالة مفتوحة لم يردّ عليها بعضهم، ولم تُتبَع بتذكير بعد
        if ($open->isNotEmpty()) {
            $latest = EmergencyMassMessage::whereIn('incident_id', $open->pluck('id'))->orderByDesc('sent_at')->orderByDesc('id')->get()->unique('incident_id');
            foreach ($latest as $m) {
                if (str_starts_with((string) $m->title, '[تذكير]')) continue; // ذُكِّروا مرة — من بقي يظهر في الحصر
                $silent = $m->responses()->whereNull('responded_at')->count();
                if (!$silent) continue;
                $inc = $open->firstWhere('id', $m->incident_id);
                $out->push(new Task(
                    key: "emsgfu:{$m->id}", module: 'الطوارئ',
                    question: ($inc?->incident_code ?? '').': '.$silent.' لم يردّ على رسالة «'.mb_substr((string) $m->title, 0, 50).'» — ذكّرهم',
                    primary: ['label' => 'ذكّرهم', 'url' => route('emergency.messages.follow-up', $m), 'method' => 'POST'],
                    secondary: $inc ? ['label' => 'شاشة الحالة', 'url' => route('emergency.incidents.live', $inc)] : null,
                    isOverdue: true, place: $inc?->place?->name, detailsUrl: $inc ? route('emergency.incidents.live', $inc) : null, createdAt: $m->sent_at,
                ));
            }
        }

        // ٢٨ — إغلاق أمني سارٍ: الرفع من لوحة المبنى (يُكتب سببه، ويُنهي حالته)
        foreach ($locks as $l) {
            if (!$l->building) continue;
            $out->push(new Task(
                key: "elock:{$l->id}", module: 'الطوارئ',
                question: 'إغلاق أمني سارٍ («'.$l->getLevelLabel().'») في '.$l->building->name.($l->reason ? ' — '.mb_substr((string) $l->reason, 0, 60) : '').' — ارفعه حين يزول السبب',
                primary: ['label' => 'ارفعه', 'url' => route('emergency.buildings.control', $l->building)],
                detailsUrl: route('emergency.buildings.control', $l->building), createdAt: $l->initiated_at,
            ));
        }

        // ٣٠ — تقرير ما بعد الحادث: مرفوع للمراجعة ← اعتمده؛ معتمد ← انشره
        foreach (AfterActionReport::whereIn('status', [AfterActionReport::STATUS_UNDER_REVIEW, AfterActionReport::STATUS_APPROVED])->with('incident')->get() as $r) {
            $code = $r->incident?->incident_code ?? ('#'.$r->id);
            $show = route('emergency.aar.show', $r);
            $review = $r->status === AfterActionReport::STATUS_UNDER_REVIEW;
            $out->push(new Task(
                key: ($review ? 'aarreview:' : 'aarpublish:').$r->id, module: 'الطوارئ',
                question: $review ? 'تقرير ما بعد الحادث '.$code.' رُفع للمراجعة — اعتمده' : 'تقرير ما بعد الحادث '.$code.' معتمد — انشره',
                primary: ['label' => $review ? 'اعتمده' : 'انشره', 'url' => $review ? route('emergency.aar.approve', $r) : route('emergency.aar.publish', $r), 'method' => 'POST'],
                secondary: ['label' => 'التقرير', 'url' => $show],
                detailsUrl: $show, createdAt: $r->updated_at,
            ));
        }

        return $out;
    }

    /** عضو بحساب في الفريق الأولي لمكان الحالة */
    private function inPlaceTeam(User $user, EmergencyIncident $inc): bool
    {
        if (!$inc->place_id) return false;
        return EmergencyTeam::active()->where('place_id', $inc->place_id)
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->exists();
    }
}
