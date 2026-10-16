<?php
/**
 * تجهيز بوابة ٣٠-٣ «كلام المكان — بطاقات الأدوار» — للقاعدة المحلية وحدها (قرار ٥٧؛ السكربت يرفض غير المحلي).
 * ينشئ أربعة حسابات موسومة «بوابة ٣٠-٣» (مسؤول سلامة، منسق سلامة فرع الدمام، موظف في قبو الدمام، منسق إدارة في الملز)
 * ويطبع المعرّفات JSON بعد السطر «JSON:». كلمة الحسابات من المتغيّر TRIAL_PW (لا تُكتب هنا).
 * التشغيل:  GATE303=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-30-3.php
 * التنظيف:  GATE303=clean php artisan tinker tests/gates/seed-30-3.php — يحذف الحسابات وما فعّلته من حالات، ويعيد ما عدّلته من بطاقات إلى أصل الملف.
 */

use App\Models\User;
use App\Modules\Emergency\Models\BuildingRoleCard;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٣٠-٣';
$mode = getenv('GATE303') ?: 'seed';

$ids = User::where('username', 'like', 'g303.%')->pluck('id')->all();
foreach (BuildingRoleCard::whereIn('edited_by_id', $ids)->get() as $c) { $c->restoreSource(); $c->save(); }
$incidents = EmergencyIncident::whereIn('triggered_by_id', $ids)->get();
foreach ($incidents as $i) {
    $i->planSteps()->delete(); $i->eventLogs()->delete(); $i->checkIns()->delete(); $i->notifications()->delete();
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
$mlzOffices = Place::where('building_id', $main->id)->where('category', 'HZ-06')->first();
$branchUnit = $dmm->branch_unit_id ? OrganizationUnit::find($dmm->branch_unit_id) : null;

$mk = function (string $u, string $name, string $role, ?int $building, ?int $place, ?int $unit) use ($pw, $TAG) {
    $user = User::create(['username' => $u, 'name' => "$name ($TAG)", 'password' => $pw, 'email' => "$u@example.test"]);
    UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'building_id' => $building, 'place_id' => $place, 'organization_unit_id' => $unit]);
    return $user->id;
};
$out = [
    'salama' => $mk('g303.salama', 'مسؤول السلامة', 'system_admin', null, null, null),
    'coord' => $mk('g303.coord', 'منسق سلامة فرع الدمام', 'safety_coordinator', $dmm->id, $dmmBasement?->id, $branchUnit?->id),
    'emp' => $mk('g303.emp', 'موظف في قبو الدمام', 'employee', $dmm->id, $dmmBasement?->id, null),
    'mlzc' => $mk('g303.mlzc', 'منسق إدارة في الملز', 'safety_coordinator', $main->id, $mlzOffices?->id, null),
    'main_building' => $main->id, 'dmm_building' => $dmm->id, 'dmm_basement' => $dmmBasement?->id,
];
echo "JSON:".json_encode($out, JSON_UNESCAPED_UNICODE)."\n";
