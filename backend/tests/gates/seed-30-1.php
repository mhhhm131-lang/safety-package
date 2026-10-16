<?php
/**
 * تجهيز بوابة ٣٠-١ «كلام المكان — خطة السلامة» — للقاعدة المحلية وحدها (قرار ٥٧: لا بيانات تجريبية على المنشور؛ السكربت يرفض غير المحلي).
 * ينشئ أربعة حسابات موسومة «بوابة ٣٠-١» (مسؤول سلامة، منسق سلامة فرع الدمام، موظف في قبو الدمام، منسق إدارة في الملز)
 * ويطبع معرّفات الأماكن JSON بعد السطر «JSON:». كلمة الحسابات من المتغيّر TRIAL_PW (لا تُكتب هنا).
 * التشغيل:  GATE301=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-30-1.php
 * التنظيف:  GATE301=clean php artisan tinker tests/gates/seed-30-1.php  — يحذف الحسابات ويعيد ما عدّلته من صفوف إلى أصل الوثيقة.
 */

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\SafetyPlanTask;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٣٠-١';
$mode = getenv('GATE301') ?: 'seed';

// ── التنظيف أولاً دائماً: حسابات البوابة وما عدّلته ──
$ids = User::where('username', 'like', 'g301.%')->pluck('id')->all();
foreach (SafetyPlanTask::whereIn('edited_by_id', $ids)->get() as $t) {
    $t->restoreSource();
    $t->save();
    $t->plan->refreshCounts();
}
\App\Modules\Governance\Models\AuditLog::whereIn('user_id', $ids)->delete(); // سجل تدقيق البوابة على المحلي وحده
UserProfile::whereIn('user_id', $ids)->delete();
User::whereIn('id', $ids)->delete();
echo "نُظّف: ".count($ids)." حساباً.\n";
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
    'salama' => $mk('g301.salama', 'مسؤول السلامة', 'system_admin', null, null, null),
    'coord' => $mk('g301.coord', 'منسق سلامة فرع الدمام', 'safety_coordinator', $dmm->id, $dmmBasement->id, $branchUnit?->id),
    'emp' => $mk('g301.emp', 'موظف في قبو الدمام', 'employee', $dmm->id, $dmmBasement->id, null),
    'mlzc' => $mk('g301.mlzc', 'منسق إدارة في الملز', 'safety_coordinator', $main->id, $mlzOffices?->id, null),
    'dmm_basement' => $dmmBasement->id, 'mlz_basement' => $mlzBasement->id,
    'branch_unit_type' => $branchUnit?->unit_type,
];
echo "JSON:".json_encode($out, JSON_UNESCAPED_UNICODE)."\n";
