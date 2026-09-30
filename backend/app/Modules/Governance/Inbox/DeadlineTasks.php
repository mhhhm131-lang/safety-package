<?php

namespace App\Modules\Governance\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EmergencyVisitor;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Services\MedicalProfileService;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\Permit;
use App\Modules\Project\Models\ContractorChannel;
use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Worker\Models\WorkerDocument;
use App\Modules\Worker\Models\WorkerTrainingRecord;
use Illuminate\Support\Collection;

/**
 * المرحلة ٢٧-ج (قرار ٦٧): المواعيد — مصدر واحد يقرأ حقول التاريخ القائمة (لا حقل جديد).
 * البطاقة تظهر قبل الموعد بسبعة أيام (الرقم القائم في الكود: شاشة المعدات ولوحة التصاريح)، تحمرّ بعده، وتختفي حين يُفعل بالمسار القائم.
 *
 *   ٣٤ تمرين مجدول                  ← من يملك التمارين: يفتح التمارين (يبدؤه أو يلغيه)
 *   ٣٥ معدة طوارئ حان فحصها          ← من يملك المعدات: «افحصها» (تُدمج بالمكان حين تتعدد)
 *   ٣٩ زوار فات موعد خروجهم          ← رئيس الأمن والسلامة والمركز: بطاقة واحدة بالعدد
 *   ٤٠ ملفات طبية لم تُراجع          ← طبيب العيادة وحده: بطاقة واحدة بعدد لوحته نفسه
 *   ٥٥ تصريح نشط تقترب نهايته        ← من يغلقه (بعد الموعد يُنهيه المجدول ليلاً فتختفي)
 *   ٥٩ معدة (تصاريح) حان فحصها       ← من يدير المعدات
 *   ٦٥ وثيقة طرف خارجي تقترب نهايتها ← المركز، وحساب الطرف نفسه (هو من يرفع الجديدة)
 *   ٦٦ وثيقة عامل تقترب نهايتها      ← من يعدّل العمال؛ حساب الطرف لعماله وحدهم
 *   ٦٧ تدريب عامل تقترب نهايته       ← من يسجّل التدريب
 *   ٦٨ تحقق مقاول من قناة مفعّلة انتهى ← المركز: «أعد التحقق» بضغطة
 *
 * الوثيقة والتدريب: سجل أحدث للنوع نفسه (للطرف/العامل نفسه) بموعد أبعد يُخفي القديم — الرفع لا يحذف القديم.
 * خارج هذا المصدر بالقراءة (لا مسار يسجّلها، فلا بطاقة تَعِد بما لا مخرج له): انتهاء صلاحية معدة الطوارئ (٣٦)،
 * تدقيق المبنى (٣٧)، انتهاء تأهيل المقاول (٦٣).
 * قراءة فقط؛ الأفعال مساراتها القائمة بصلاحياتها.
 */
class DeadlineTasks implements TaskSource
{
    /** الأيام قبل الموعد — الرقم القائم: equipment/index.blade.php (≤ ٧ أيام) ولوحة التصاريح «تنتهي خلال ٧ أيام» */
    public const DAYS = 7;

    private const CENTER = ['system_admin', 'system_staff'];

    public function tasksFor(User $user): Collection
    {
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return collect();
        $role = $user->role();
        $can = fn (string $p) => PermissionRegistry::hasPermission($role, $p);
        $until = now()->addDays(self::DAYS);
        // أدوار الأطراف الخارجية ترى طرفها وحده (AppliesOrgUnitScope::assertPartyAccess)
        $partyOnly = $user->isContractorRole() ? (int) $user->external_party_id : null;
        $out = collect();
        $label = fn ($p) => $p ? $p->code.' '.$p->name : null;

        // ٣٤ — التمارين
        if ($can('emergency.drill')) {
            foreach (EvacuationDrill::where('status', EvacuationDrill::STATUS_SCHEDULED)->where('scheduled_at', '<=', $until)->with(['place', 'building'])->get() as $d) {
                $late = $d->scheduled_at->isPast();
                $where = $d->place?->name ?? $d->building?->name ?? '';
                $out->push(new Task(
                    key: "drill:{$d->id}", module: 'الطوارئ',
                    question: 'تمرين «'.$d->getTypeLabel().'» في '.$where.($late ? ': فات موعده ('.$d->scheduled_at->format('Y-m-d').') — ابدأه أو ألغه' : ' موعده '.$d->scheduled_at->format('Y-m-d H:i').' — استعدّ له'),
                    primary: ['label' => 'افتح التمارين', 'url' => route('emergency.drills.index')],
                    dueAt: $d->scheduled_at, isOverdue: $late, place: $label($d->place),
                    detailsUrl: route('emergency.drills.index'), createdAt: $d->created_at,
                ));
            }
        }

        // ٣٥ — معدات الطوارئ
        if ($can('emergency.equipment')) {
            foreach (EmergencyEquipment::whereNotNull('next_inspection_date')->whereDate('next_inspection_date', '<=', $until->toDateString())->with('place')->get() as $e) {
                $late = $e->next_inspection_date->lt(today());
                $name = $e->getTypeLabel().($e->code ? ' '.$e->code : '');
                $url = route('emergency.equipment.index', $e->place ? ['place' => $e->place->code] : []);
                $out->push(new Task(
                    key: "eqinspect:{$e->id}", module: 'الطوارئ',
                    question: $name.($e->place ? ' في '.$e->place->name : '').': '.($late ? 'فات موعد فحصها' : 'فحصها الدوري').' '.$e->next_inspection_date->format('Y-m-d').' — افحصها',
                    primary: ['label' => 'افحصها', 'url' => $url],
                    dueAt: $e->next_inspection_date, isOverdue: $late, place: $label($e->place),
                    detailsUrl: $url, createdAt: $e->next_inspection_date,
                    batch: 'equipment.inspect', item: $name.' — '.$e->next_inspection_date->format('Y-m-d'),
                ));
            }
        }

        // ٣٩ — الزوار: فات موعد خروجه، أو دخل قبل اليوم بلا موعد خروج
        if ($role === 'security_safety_head' || in_array($role, self::CENTER, true)) {
            $stale = EmergencyVisitor::currentlyIn()
                ->where(fn ($q) => $q->where('expected_checkout_at', '<', now())
                    ->orWhere(fn ($w) => $w->whereNull('expected_checkout_at')->where('checked_in_at', '<', today())))
                ->with('building')->get()->groupBy('building_id');
            foreach ($stale as $buildingId => $list) {
                $b = $list->first()->building;
                if (!$b) continue;
                $out->push(new Task(
                    key: "visitors:{$buildingId}", module: 'الطوارئ',
                    question: 'زوار فات موعد خروجهم ولم يُسجَّل خروجهم: '.$list->count().' — سجّل خروجهم',
                    primary: ['label' => 'الزوار', 'url' => route('emergency.visitors.building', $b)],
                    isOverdue: true, detailsUrl: route('emergency.visitors.building', $b), createdAt: $list->min('checked_in_at'),
                ));
            }
        }

        // ٤٠ — الملفات الطبية: الاستعلام نفسه الذي في لوحة الطبيب
        if ($can('medical.read')) {
            $n = app(MedicalProfileService::class)->getProfilesNeedingReview()->count();
            if ($n) {
                $out->push(new Task(
                    key: 'medreview', module: 'الطوارئ',
                    question: 'ملفات طبية لم تُراجع منذ ستة أشهر أو لم تُراجع قط: '.$n.' — راجعها',
                    primary: ['label' => 'راجعها', 'url' => route('emergency.medical.dashboard')],
                    detailsUrl: route('emergency.medical.dashboard'),
                ));
            }
        }

        // ٥٥ — تصريح نشط تقترب نهايته
        if ($can('permit.activate')) {
            foreach (Permit::where('status', Permit::STATUS_ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<=', $until)->with('place')->get() as $p) {
                if (!$user->can('complete', $p)) continue;
                $late = $p->expires_at->isPast();
                $out->push(new Task(
                    key: "permit:{$p->id}:expiring", module: 'التصاريح',
                    question: 'تصريح '.$p->code.' «'.$p->title.'» نشط — '.($late ? 'انتهت مدته ('.$p->expires_at->format('m/d H:i').') — أغلقه' : 'ينتهي '.$p->expires_at->format('m/d H:i').' — أغلقه حين ينتهي العمل'),
                    primary: ['label' => 'افتحه', 'url' => route('permits.show', $p)],
                    dueAt: $p->expires_at, isOverdue: $late, place: $label($p->place),
                    detailsUrl: route('permits.show', $p), createdAt: $p->updated_at,
                ));
            }
        }

        // ٥٩ — معدات التصاريح (المستبعدة لا تُفحص)
        if ($can('epc.manage')) {
            foreach (Equipment::where('status', '!=', 'retired')->whereNotNull('next_inspection_date')->whereDate('next_inspection_date', '<=', $until->toDateString())->get() as $e) {
                $late = $e->next_inspection_date->lt(today());
                $out->push(new Task(
                    key: "epceq:{$e->id}", module: 'التصاريح',
                    question: 'معدة «'.$e->name.'»'.($e->code ? ' '.$e->code : '').': '.($late ? 'فات موعد فحصها' : 'فحصها الدوري').' '.$e->next_inspection_date->format('Y-m-d').' — افحصها',
                    primary: ['label' => 'افحصها', 'url' => route('equipment.show', $e)],
                    dueAt: $e->next_inspection_date, isOverdue: $late,
                    detailsUrl: route('equipment.show', $e), createdAt: $e->next_inspection_date,
                ));
            }
        }

        // ٦٥ — وثائق الأطراف الخارجية الموثّقة
        $center = $can('external_party.edit');
        if ($center || ($partyOnly && $can('external_party.list'))) {
            $docs = ExternalPartyDocument::where('is_verified', true)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $until->toDateString())
                ->when($partyOnly !== null, fn ($q) => $q->where('external_party_id', $partyOnly))->with('externalParty')->get();
            foreach ($docs as $d) {
                $newer = ExternalPartyDocument::where('external_party_id', $d->external_party_id)->where('document_type', $d->document_type)->where('id', '>', $d->id)
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', $d->expiry_date->toDateString()))->exists();
                if ($newer || !$d->externalParty) continue;
                $late = $d->expiry_date->lt(today());
                $url = route('external-parties.documents', $d->external_party_id).'#doc-'.$d->id;
                $out->push(new Task(
                    key: "epdoc:{$d->id}:expiry", module: 'المقاولون',
                    question: 'وثيقة «'.$d->name.'» '.($partyOnly !== null ? 'لطرفكم' : 'من «'.$d->externalParty->name.'»').' '.($late ? 'انتهت' : 'تنتهي').' '.$d->expiry_date->format('Y-m-d')
                        .($partyOnly !== null ? ' — ارفع الجديدة' : ' — اطلب تجديدها'),
                    primary: ['label' => $partyOnly !== null ? 'ارفع الجديدة' : 'الوثائق', 'url' => $url],
                    dueAt: $d->expiry_date, isOverdue: $late, detailsUrl: $url, createdAt: $d->expiry_date,
                ));
            }
        }

        // ٦٦ — وثائق العمال (المحظور لا يُطلب تجديد وثائقه)
        if ($can('worker.edit') && $partyOnly !== 0) {
            $docs = WorkerDocument::whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $until->toDateString())
                ->whereHas('worker', fn ($q) => $q->where('status', '!=', 'blocked')->when($partyOnly !== null, fn ($w) => $w->where('external_party_id', $partyOnly)))
                ->with('worker')->get();
            foreach ($docs as $d) {
                $newer = WorkerDocument::where('worker_id', $d->worker_id)->where('document_type', $d->document_type)->where('id', '>', $d->id)
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', $d->expiry_date->toDateString()))->exists();
                if ($newer) continue;
                $late = $d->expiry_date->lt(today());
                $out->push(new Task(
                    key: "wdoc:{$d->id}:expiry", module: 'المقاولون',
                    question: 'وثيقة «'.$d->name.'» للعامل «'.$d->worker->full_name.'» '.($late ? 'انتهت' : 'تنتهي').' '.$d->expiry_date->format('Y-m-d').' — جدّدها',
                    primary: ['label' => 'وثائقه', 'url' => route('workers.documents', $d->worker_id)],
                    dueAt: $d->expiry_date, isOverdue: $late, detailsUrl: route('workers.show', $d->worker_id), createdAt: $d->expiry_date,
                ));
            }
        }

        // ٦٧ — تدريب العمال
        if ($can('worker.manage')) {
            $recs = WorkerTrainingRecord::where('status', 'completed')->whereNotNull('expires_at')->whereDate('expires_at', '<=', $until->toDateString())
                ->whereHas('worker', fn ($q) => $q->where('status', '!=', 'blocked'))->with(['worker', 'trainingTopic'])->get();
            foreach ($recs as $r) {
                $newer = WorkerTrainingRecord::where('worker_id', $r->worker_id)->where('training_topic_id', $r->training_topic_id)->where('id', '>', $r->id)
                    ->whereIn('status', ['completed', 'waived'])
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>', $r->expires_at->toDateString()))->exists();
                if ($newer) continue;
                $late = $r->expires_at->lt(today());
                $out->push(new Task(
                    key: "wtrain:{$r->id}:expiry", module: 'المقاولون',
                    question: 'تدريب «'.($r->trainingTopic?->name ?? '—').'» للعامل «'.$r->worker->full_name.'» '.($late ? 'انتهى' : 'ينتهي').' '.$r->expires_at->format('Y-m-d').' — جدّده',
                    primary: ['label' => 'افتحه', 'url' => route('workers.show', $r->worker_id)],
                    dueAt: $r->expires_at, isOverdue: $late, detailsUrl: route('workers.show', $r->worker_id), createdAt: $r->expires_at,
                ));
            }
        }

        // ٦٨ — تحقق المقاول: للقنوات المفعّلة المضبوطة وحدها (غيرها لا مخرج له)
        if ($center) {
            $channels = ContractorChannel::enabledByType()->filter(fn (ContractorChannel $c) => $c->isConfigured());
            if ($channels->isNotEmpty()) {
                $rows = ContractorVerification::whereIn('source_channel', $channels->keys()->all())->whereNotNull('expires_at')->where('expires_at', '<=', $until)
                    ->with('externalParty')->get()->groupBy(fn ($v) => $v->external_party_id.':'.$v->source_channel);
                foreach ($rows as $list) {
                    $v = $list->sortBy('expires_at')->first();
                    if (!$v->externalParty) continue;
                    $late = $v->expires_at->isPast();
                    $out->push(new Task(
                        key: "cverify:{$v->external_party_id}:{$v->source_channel}", module: 'المقاولون',
                        question: 'تحقق «'.$v->externalParty->name.'» من «'.(ContractorChannel::LABELS[$v->source_channel] ?? $v->source_channel).'» '.($late ? 'انتهى' : 'ينتهي').' '.$v->expires_at->format('Y-m-d').' — أعد التحقق',
                        primary: ['label' => 'أعد التحقق', 'url' => route('external-parties.enrich', $v->external_party_id), 'method' => 'POST'],
                        secondary: ['label' => 'ملفه', 'url' => route('external-parties.profile', $v->external_party_id)],
                        dueAt: $v->expires_at, isOverdue: $late, detailsUrl: route('external-parties.profile', $v->external_party_id), createdAt: $v->verified_at,
                    ));
                }
            }
        }

        return $out;
    }
}
