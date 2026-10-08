<?php

namespace App\Modules\Risk\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Support\RiskApproval;
use Illuminate\Support\Collection;

/**
 * طابور اعتماد المخاطر (RiskController::approvalQueue) بصيغة مهام: خطر بانتظار الاعتماد ← «اعتمد» / «التفاصيل».
 * قرار ٦٩ (٢٠٢٦-٠٩-٣٠): خطر الإدارة يعتمده مديرها، والسجل العام مسؤول السلامة وحده — `RiskApproval::canApprove`.
 */
class RiskTasks implements TaskSource
{
    /** نطاق سجل الإدارات نفسه: أدوار السجل كله ترى الكل؛ غيرها — المدير ومنسق السلامة (قرار ٧٠) — وحدته وما تحتها + بلا وحدة. */
    private const GLOBAL = RiskApproval::REGISTER_WIDE;

    public function tasksFor(User $user): Collection
    {
        $profile = UserProfile::where('user_id', $user->id)->first();
        if (!$profile) return collect();
        $out = collect();

        // ١١-٣: اقتراح النظام — مدير إدارة يملك التفعيل وإدارته بلا مخاطر مفعّلة ← يبدأ من كتاب المعهد (لا تُفعَّل شيء عنه)
        // قرار ٧٠: ومنسق سلامتها مثله — الوحدة تأخذ من السجل العام عبر منسقها
        if ($profile->organization_unit_id && PermissionRegistry::hasPermission($profile->role, 'risk.activate')
            && !in_array($profile->role, self::GLOBAL, true)
            && !Risk::where('risk_type', 'active')->where('organization_unit_id', $profile->organization_unit_id)->exists()) {
            $unit = OrganizationUnit::find($profile->organization_unit_id);
            $out->push(new Task(
                key: "risk:suggest:{$profile->organization_unit_id}", module: 'المخاطر',
                question: 'إدارة «'.($unit?->name ?? '').'» بلا مخاطر مفعّلة — اختر أخطارها من سجل المعهد',
                primary: ['label' => 'ابدأ من السجل', 'url' => route('risk.reference.index')],
                detailsUrl: route('risk.reference.index'),
            ));
        }
        // ٢٧-ب (قرار ٦٧): خطر كتبه ولم يكتمل طريقه — مسودة لم تُقدَّم ← «قدّمه»؛ مرفوض ← «أعده للتعديل» بسبب الرفض.
        // لمن يملك الرفع: من يملك الإنشاء (المنسق والمركز)، ومدير الإدارة لخطره هو (قرار ٦٩ — الحارس نفسه في RiskController::submit).
        if (PermissionRegistry::hasPermission($profile->role, 'risk.create') || PermissionRegistry::hasPermission($profile->role, 'risk.activate')) {
            foreach (Risk::where('created_by_id', $user->id)->whereIn('status', ['draft', 'rejected'])->get() as $r) {
                $edit = $r->risk_type === 'reference' ? route('risk.reference.edit', $r)
                    : (PermissionRegistry::hasPermission($profile->role, 'risk.activate') ? route('risk.active.edit', $r) : route('risk.show', $r));
                if ($r->status === 'draft') {
                    $out->push(new Task(
                        key: "risk:{$r->id}:draft", module: 'المخاطر',
                        question: 'خطر «'.$r->title.'» مسودة لم تُقدَّم — قدّمه للاعتماد',
                        primary: ['label' => 'قدّمه', 'url' => route('risk.submit', $r), 'method' => 'POST'],
                        secondary: ['label' => 'عدّله', 'url' => $edit],
                        detailsUrl: route('risk.show', $r), createdAt: $r->updated_at,
                    ));
                } elseif ($r->risk_type === 'reference') {
                    // قرار ٧٦: مقترح للسجل العام رُفض ← «صحّحه» تفتح نموذجه، وحفظه يعيد إرساله (ضغطتان لا أربع)
                    $out->push(new Task(
                        key: "risk:{$r->id}:rejected", module: 'المخاطر',
                        question: 'مقترحك «'.$r->title.'» رُفض'.($r->approval_notes ? ': '.mb_substr((string) $r->approval_notes, 0, 80) : '').' — صحّحه، والحفظ يعيد إرساله',
                        primary: ['label' => 'صحّحه', 'url' => $edit],
                        secondary: ['label' => 'التفاصيل', 'url' => route('risk.show', $r)],
                        detailsUrl: route('risk.show', $r), createdAt: $r->updated_at,
                    ));
                } else {
                    $out->push(new Task(
                        key: "risk:{$r->id}:rejected", module: 'المخاطر',
                        question: 'خطر «'.$r->title.'» رُفض'.($r->approval_notes ? ': '.mb_substr((string) $r->approval_notes, 0, 80) : '').' — أعده للتعديل ثم قدّمه',
                        primary: ['label' => 'أعده للتعديل', 'url' => route('risk.changeStatus', ['risk' => $r, 'status' => 'draft']), 'method' => 'POST'],
                        secondary: ['label' => 'التفاصيل', 'url' => route('risk.show', $r)],
                        detailsUrl: route('risk.show', $r), createdAt: $r->updated_at,
                    ));
                }
            }
        }
        // خطة المعالج — الخطوة ٥ (٢٠٢٦-١٠-٠٨، بكلمته «ويحمي نفسه»): النقص يُرى قبل أن يقع فيه بلاغ.
        // مسؤول السلامة: أخطار العام بلا إدارة معالجة، وأماكن بلا فني لتخصص سُمّي على خطر. مدير الإدارة المعالجة: أخطار إدارته بلا معالج.
        $out = $out->merge($this->uncovered($user, $profile));

        if (!PermissionRegistry::hasPermission($profile->role, 'risk.approve')) return $out;

        // قرار ٦٩: البطاقة لمن يعتمد هذا الخطر — مدير وحدته (أو ما فوقها)، ومسؤول السلامة للسجل العام وما بلا وحدة
        $pending = Risk::where('status', 'pending_approval')->with('organizationUnit')->get()->toBase()
            ->filter(fn (Risk $r) => RiskApproval::canApprove($user, $r));
        return $out->merge($pending->map(fn (Risk $r) => new Task(
            key: "risk:{$r->id}:approve",
            module: 'المخاطر',
            question: 'خطر «'.$r->title.'»'.($r->organizationUnit ? ' في «'.$r->organizationUnit->name.'»' : '').' ينتظر اعتمادك',
            primary: ['label' => 'اعتمد', 'url' => route('risk.approve', $r), 'method' => 'POST'],
            secondary: ['label' => 'التفاصيل', 'url' => route('risk.show', $r)],
            detailsUrl: route('risk.show', $r),
            createdAt: $r->created_at,
            // قرار ٧١: ما ينتظر الاعتماد في الوحدة نفسها بطاقة واحدة بعددها و«اعتمدها كلها»؛ كل بند باسمه وزره تحتها
            place: $r->organizationUnit ? '«'.$r->organizationUnit->name.'»' : ($r->risk_type === 'active' ? 'نطاق المعهد كله' : 'السجل العام'),
            batch: 'risk.approve',
            item: $r->title,
        ))->values());
    }

    /** الخطوة ٥: بطاقات «غير المغطى» — عدّاد يرتفع بإفراغ الخانة وينزل بملئها؛ كل بطاقة تفتح الشاشة التي تملؤه */
    private function uncovered(User $user, UserProfile $profile): Collection
    {
        $out = collect();
        $general = Risk::where('risk_type', 'reference')->adopted();
        if ($profile->role === RiskApproval::GENERAL_APPROVER) {
            $n = (clone $general)->whereNull('handling_unit_id')->whereNull('handling_unit_name')->count();
            if ($n > 0) {
                $out->push(new Task(
                    key: 'risk:uncovered:units', module: 'المخاطر',
                    question: self::count($n, 'خطر', 'خطران', 'أخطار', 'خطراً').' في السجل العام بلا إدارة معالجة — بلاغها يقف عند المركز',
                    primary: ['label' => 'علّق الإدارات', 'url' => route('risk.reference.index')],
                    detailsUrl: route('risk.reference.index'),
                ));
            }
            // أماكن بلا فني لتخصص سُمّي على خطر: لكل تخصص مسمّى، الأماكن التي لا يغطيها فني مفعّل به (تغطيةً أو مكان حسابه بلا تغطية)
            $specs = (clone $general)->whereNotNull('handler_specialty')->distinct()->pluck('handler_specialty');
            if ($specs->isNotEmpty()) {
                $places = \App\Modules\Governance\Models\Place::orderBy('sort')->get(['id', 'name', 'code']);
                $gaps = [];
                foreach ($specs as $spec) {
                    $covered = UserProfile::where('role', $spec)->where('is_active', true)->with('coverage')->get()
                        ->flatMap(fn (UserProfile $p) => $p->coverage->isNotEmpty() ? $p->coverage->pluck('id') : collect([$p->place_id]))->filter()->unique();
                    foreach ($places as $pl) {
                        if (!$covered->contains($pl->id)) $gaps[] = (PermissionRegistry::ROLES[$spec] ?? $spec).' في '.$pl->name;
                    }
                }
                if ($gaps) {
                    $out->push(new Task(
                        key: 'risk:uncovered:places', module: 'المخاطر',
                        question: self::count(count($gaps), 'مكان', 'مكانان', 'أماكن', 'مكاناً').' بلا فني لتخصص مسمّى على خطر — بلاغه يذهب لمدير الإدارة المعالجة: '.implode('، ', array_slice($gaps, 0, 3)).(count($gaps) > 3 ? '…' : ''),
                        primary: ['label' => 'الحسابات', 'url' => route('app.users.index')],
                        detailsUrl: route('app.users.index'),
                    ));
                }
            }
        }
        if (in_array($profile->role, RiskApproval::UNIT_MANAGERS, true) && $profile->organization_unit_id) {
            $ids = OrganizationUnit::descendantIdsOf($profile->organization_unit_id);
            $n = (clone $general)->whereIn('handling_unit_id', $ids)->whereNull('handler_specialty')->whereNull('handler_user_id')->count();
            if ($n > 0) {
                $out->push(new Task(
                    key: 'risk:uncovered:handlers', module: 'المخاطر',
                    question: self::count($n, 'خطر', 'خطران', 'أخطار', 'خطراً').' على إدارتك بلا معالج — بلاغه يصلك أنت حتى تسمّي',
                    primary: ['label' => 'سمِّ المعالجين', 'url' => route('risk.handlers.index')],
                    detailsUrl: route('risk.handlers.index'),
                ));
            }
        }
        return $out;
    }

    /** «خطر واحد» · «خطران» · «٣ أخطار» · «١١ خطراً» */
    private static function count(int $n, string $one, string $two, string $few, string $many): string
    {
        if ($n === 1) return $one.' واحد';
        if ($n === 2) return $two;
        return $n.' '.($n <= 10 ? $few : $many);
    }
}
