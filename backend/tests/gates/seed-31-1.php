<?php
/**
 * تجهيز بوابة ٣١-١ «المقارنة لكل مبنى» — للقاعدة المحلية وحدها (قرار ٥٧؛ السكربت يرفض غير المحلي).
 * ينشئ أربعة حسابات موسومة «بوابة ٣١-١» ويطبع المعرّفات JSON بعد السطر «JSON:». كلمة الحسابات من TRIAL_PW.
 * التشغيل:  GATE311=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-31-1.php
 * التنظيف:  GATE311=clean php artisan tinker tests/gates/seed-31-1.php — يحذف الحسابات ويعيد ما عدّلته من صفوف إلى أصل الوثيقة.
 */

use App\Models\User;
use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٣١-١';
$mode = getenv('GATE311') ?: 'seed';

$ids = User::where('username', 'like', 'g311.%')->pluck('id')->all();
foreach (BuildingComplianceItem::whereIn('edited_by_id', $ids)->get() as $r) { $r->restoreSource(); $r->save(); }
AuditLog::whereIn('user_id', $ids)->delete();
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
$mlzOffices = Place::where('building_id', $main->id)->where('category', 'HZ-06')->first();
$branchUnit = $dmm->branch_unit_id ? OrganizationUnit::find($dmm->branch_unit_id) : null;

$mk = function (string $u, string $name, string $role, ?int $building, ?int $place, ?int $unit) use ($pw, $TAG) {
    $user = User::create(['username' => $u, 'name' => "$name ($TAG)", 'password' => $pw, 'email' => "$u@example.test"]);
    UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'building_id' => $building, 'place_id' => $place, 'organization_unit_id' => $unit]);
    return $user->id;
};
$out = [
    'salama' => $mk('g311.salama', 'مسؤول السلامة', 'system_admin', null, null, null),
    'coord' => $mk('g311.coord', 'منسق سلامة فرع الدمام', 'safety_coordinator', $dmm->id, $dmmBasement?->id, $branchUnit?->id),
    'emp' => $mk('g311.emp', 'موظف في قبو الدمام', 'employee', $dmm->id, $dmmBasement?->id, null),
    'mlzc' => $mk('g311.mlzc', 'منسق إدارة في الملز', 'safety_coordinator', $main->id, $mlzOffices?->id, null),
    'main_building' => $main->id, 'dmm_building' => $dmm->id,
];
echo "JSON:".json_encode($out, JSON_UNESCAPED_UNICODE)."\n";
