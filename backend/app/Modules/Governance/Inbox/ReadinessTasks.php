<?php

namespace App\Modules\Governance\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Services\PlaceProfile;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Collection;

/**
 * المرحلة ٢٧-ب (قرار ٦٧): الاستعداد قبل الحالة — ما ينقص يصل صاحبه بطاقةً، ويختفي حين يُسدّ.
 *
 *   ٤٣ فريق فعالية قادمة في القاعات رُشّح ولم يُعتمد ← رئيس الأمن والسلامة وحده (قرار ٤٣): «اعتمده» بضغطة
 *   ٣٨ أماكن بلا خطة استجابة في النظام ← المركز: «زامنها» (الحالة في مكان بلا خطة تبدأ بلا قائمة خطوات — IncidentStepsService::seed)
 *   ٧٤ إدارة بلا منسق سلامة ← مديرها: «رشّح منسقاً» (منسق السلامة في الوحدة أو ما فوقها أو ما تحتها يكفي؛ المرشَّح قبل اعتماده يُحسب)
 *
 * قراءة فقط؛ الأفعال مساراتها القائمة بصلاحياتها.
 */
class ReadinessTasks implements TaskSource
{
    private const CENTER = ['system_admin', 'system_staff'];

    public function tasksFor(User $user): Collection
    {
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return collect();
        $role = $user->role();
        $out = collect();

        // ٤٣ — فرق الفعاليات
        if (PlaceProfile::canApproveEvent($user) && ($halls = Place::where('code', PlaceProfile::HALLS)->first())) {
            $today = now()->toDateString();
            foreach (PlaceProfile::events(PlaceProfile::get(PlaceProfile::HALLS)) as $i => $e) {
                $upcoming = empty($e['date']) || $e['date'] >= $today;
                if (!$upcoming || empty($e['nom']['date']) || !empty($e['appr']['date']) || !PlaceProfile::named($e['team'])) continue;
                $file = route('app.places.units.file', $halls).'#pfEvents';
                $out->push(new Task(
                    key: 'eventteam:'.$i.':'.substr(md5(($e['name'] ?? '').'|'.($e['date'] ?? '')), 0, 8),
                    module: 'الفريق الأولي',
                    question: 'فعالية «'.($e['name'] ?? '—').'»'.(!empty($e['date']) ? ' ('.$e['date'].')' : '').' في '.$halls->name.': فريقها مرشَّح — اعتمده',
                    primary: ['label' => 'اعتمده', 'url' => route('app.places.team.event.approve', ['place' => $halls, 'i' => $i]), 'method' => 'POST'],
                    secondary: ['label' => 'الفريق', 'url' => $file],
                    dueAt: !empty($e['date']) ? \Carbon\Carbon::parse($e['date']) : null,
                    place: $halls->code.' '.$halls->name,
                    detailsUrl: $file,
                ));
            }
        }

        // ٣٨ — أماكن بلا خطة استجابة في النظام
        if (in_array($role, self::CENTER, true)) {
            $have = ResponsePlan::pluck('place_id')->all();
            $missing = Place::where('code', '!=', 'HZ-00')->whereNotIn('id', $have)->orderBy('sort')->pluck('name');
            if ($missing->isNotEmpty()) {
                $out->push(new Task(
                    key: 'emplans',
                    module: 'الطوارئ',
                    question: 'أماكن بلا خطة استجابة في النظام: '.$missing->count().' ('.$missing->take(3)->implode('، ').($missing->count() > 3 ? '…' : '').') — الحالة فيها تبدأ بلا قائمة خطوات — زامن الخطط',
                    primary: ['label' => 'زامنها', 'url' => route('emergency.plans.sync'), 'method' => 'POST'],
                    secondary: ['label' => 'خطط الاستجابة', 'url' => route('emergency.plans.index')],
                    detailsUrl: route('emergency.plans.index'),
                ));
            }
        }

        // ٧٤ — إدارتي بلا منسق سلامة (من يرشّح منسق وحدته: مدير الفرع/الإدارة/القسم — لا مدير المرافق، ترشيحه للفنيين)
        if (PermissionRegistry::hasPermission($role, 'system.users.own') && $role !== 'facilities_manager' && $profile->organization_unit_id) {
            $unit = OrganizationUnit::find($profile->organization_unit_id);
            if ($unit && $unit->is_active && !$this->covered($unit)) {
                $out->push(new Task(
                    key: 'coordgap:'.$unit->id,
                    module: 'الحسابات',
                    question: '«'.$unit->name.'» بلا منسق سلامة — رشّح منسقاً (يعمل بعد اعتماد مسؤول السلامة)',
                    primary: ['label' => 'رشّح منسقاً', 'url' => route('app.users.create', [], false)],
                    detailsUrl: route('app.users.index', [], false),
                ));
            }
        }

        return $out;
    }

    /**
     * للوحدة منسق سلامة: فيها، أو في ما تحتها، أو في ما فوقها — فعّالاً، أو مرشَّحاً ينتظر الاعتماد،
     * أو مرشَّحاً أُعيد للتصحيح (بطاقته «صحّحه» تسأل من سجّله — لا بطاقتان للسؤال نفسه).
     */
    private function covered(OrganizationUnit $unit): bool
    {
        $ids = array_merge([$unit->id], OrganizationUnit::descendantIdsOf($unit->id));
        for ($u = $unit, $n = 0; $u->parent_id && $n < 10; $n++) {
            $u = OrganizationUnit::find($u->parent_id);
            if (!$u) break;
            $ids[] = $u->id;
        }
        return UserProfile::where('role', 'safety_coordinator')->whereIn('organization_unit_id', array_unique($ids))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('pending_since')->orWhereNotNull('return_note'))->exists();
    }
}
