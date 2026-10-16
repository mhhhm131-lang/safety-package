<?php
/**
 * تجهيز بوابة ٣٠-٢ «كلام المكان — خطة الاستجابة» — للقاعدة المحلية وحدها (قرار ٥٧؛ السكربت يرفض غير المحلي).
 * ينشئ أربعة حسابات موسومة «بوابة ٣٠-٢» (مسؤول سلامة، منسق سلامة فرع الدمام، موظف في قبو الدمام، منسق إدارة في الملز)
 * ويطبع المعرّفات JSON بعد السطر «JSON:». كلمة الحسابات من المتغيّر TRIAL_PW (لا تُكتب هنا).
 * التشغيل:  GATE302=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-30-2.php
 * التنظيف:  GATE302=clean php artisan tinker tests/gates/seed-30-2.php — يحذف الحسابات وما فعّلته من حالات، ويعيد ما عدّلته من خطوات إلى أصل الوثيقة.
 */

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٣٠-٢';
$mode = getenv('GATE302') ?: 'seed';

// ── التنظيف أولاً دائماً: حسابات البوابة وما عدّلته وما فعّلته ──
$ids = User::where('username', 'like', 'g302.%')->pluck('id')->all();
foreach (ResponsePlanStep::whereIn('edited_by_id', $ids)->get() as $s) {
    $s->restoreSource();
    $s->save();
    $s->plan->refreshCounts();
}
$incidents = EmergencyIncident::whereIn('triggered_by_id', $ids)->get();
foreach ($incidents as $i) {
    $i->planSteps()->delete();
    $i->eventLogs()->delete();
    $i->checkIns()->delete();
    $i->notifications()->delete();
    foreach ($i->massMessages()->get() as $m) { $m->responses()->delete(); $m->delete(); }
    $i->delete();
}
AuditLog::whereIn('user_id', $ids)->delete();
UserProfile::whereIn('user_id', $ids)->delete();
User::whereIn('id', $ids)->delete();
echo "نُظّف: ".count($ids)." حساباً و".$incidents->count()." حالة.\n";
if ($mode === 'clean') return;

$pw = getenv('TRIAL_PW');
if (!$pw) { echo "مطلوب TRIAL_PW.\n"; return; }

$main = EmergencyBuilding::main();
$dmm = EmergencyBuilding::where('code', 'DMM')->first();
if (!$dmm) { echo "لا مبنى DMM في القاعدة المحلية.\n"; return; }
$dmmBasement = Place::where('building_id', $dmm->id)->where('category', 'HZ-01')->first();
$mlzBasement = Place::where('building_id', $main->id)->where('category', 'HZ-01')->first();
$mlzOffices = Place::where('building_id', $main->id)->where('category', 'HZ-06')->first();
if (!$dmmBasement || !$dmmBasement->is_active) { echo "قبو الدمام غير موجود أو معطَّل.\n"; return; }
$branchUnit = $dmm->branch_unit_id ? OrganizationUnit::find($dmm->branch_unit_id) : null;

$mk = function (string $u, string $name, string $role, ?int $building, ?int $place, ?int $unit) use ($pw, $TAG) {
    $user = User::create(['username' => $u, 'name' => "$name ($TAG)", 'password' => $pw, 'email' => "$u@example.test"]);
    UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'building_id' => $building, 'place_id' => $place, 'organization_unit_id' => $unit]);
    return $user->id;
};
$out = [
    'salama' => $mk('g302.salama', 'مسؤول السلامة', 'system_admin', null, null, null),
    'coord' => $mk('g302.coord', 'منسق سلامة فرع الدمام', 'safety_coordinator', $dmm->id, $dmmBasement->id, $branchUnit?->id),
    'emp' => $mk('g302.emp', 'موظف في قبو الدمام', 'employee', $dmm->id, $dmmBasement->id, null),
    'mlzc' => $mk('g302.mlzc', 'منسق إدارة في الملز', 'safety_coordinator', $main->id, $mlzOffices?->id, null),
    'dmm_building' => $dmm->id, 'dmm_basement' => $dmmBasement->id, 'mlz_basement' => $mlzBasement->id,
    'open_in_dmm' => EmergencyIncident::open()->where('building_id', $dmm->id)->count(),
];
echo "JSON:".json_encode($out, JSON_UNESCAPED_UNICODE)."\n";
