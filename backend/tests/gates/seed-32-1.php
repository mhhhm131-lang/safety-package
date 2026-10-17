<?php
/**
 * تجهيز بوابة ٣٢-١ «فرع واحد ظاهر والرسم يتبعه» (قرار ٩١) — للقاعدة المحلية وحدها (قرار ٥٧؛ السكربت يرفض غير المحلي).
 * ينشئ حسابين موسومين «بوابة ٣٢-١» (مسؤول سلامة يرى الكل، ومدير فرع الدمام) وبلاغ شاغل مفتوحاً في قبو الدمام،
 * ويطبع المعرّفات JSON بعد السطر «JSON:». كلمة الحسابات من TRIAL_PW.
 * التشغيل:  GATE321=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-32-1.php
 * التنظيف:  GATE321=clean php artisan tinker tests/gates/seed-32-1.php — يحذف الحسابين والبلاغ.
 */

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٣٢-١';
$mode = getenv('GATE321') ?: 'seed';

$ids = User::where('username', 'like', 'g321.%')->pluck('id')->all();
$n = 0;
foreach (Incident::withoutGlobalScopes()->where('code', 'like', 'ش-321%')->get() as $i) { method_exists($i, 'forceDelete') ? $i->forceDelete() : $i->delete(); $n++; }
AuditLog::whereIn('user_id', $ids)->delete();
UserProfile::whereIn('user_id', $ids)->delete();
User::whereIn('id', $ids)->delete();
echo "نُظّف: ".count($ids)." حساباً و$n بلاغاً.\n";
if ($mode === 'clean') return;

$pw = getenv('TRIAL_PW');
if (!$pw) { echo "مطلوب TRIAL_PW.\n"; return; }
$main = EmergencyBuilding::main();
$dmm = EmergencyBuilding::where('code', 'DMM')->first();
if (!$dmm) { echo "لا مبنى DMM في القاعدة المحلية.\n"; return; }
$dmmBasement = Place::where('building_id', $dmm->id)->where('category', 'HZ-01')->first();
if (!$dmmBasement) { echo "لا قبو في الدمام.\n"; return; }

$mk = function (string $u, string $name, string $role, ?int $building, ?int $place, ?int $unit) use ($pw, $TAG) {
    $user = User::create(['username' => $u, 'name' => "$name ($TAG)", 'password' => $pw, 'email' => "$u@example.test"]);
    UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'building_id' => $building, 'place_id' => $place, 'organization_unit_id' => $unit]);
    return $user->id;
};
$inc = Incident::create(['code' => 'ش-3210', 'title' => "بلاغ قبو الدمام ($TAG)", 'description' => "بلاغ شاغل مفتوح في قبو الدمام ($TAG)", 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $dmmBasement->id]);
$out = [
    'salama' => $mk('g321.salama', 'مسؤول السلامة', 'system_admin', null, null, null),
    'mgr' => $mk('g321.mgr', 'مدير فرع الدمام', 'branch_manager', $dmm->id, null, $dmm->branch_unit_id),
    'incident' => $inc->id,
    'main_building' => $main->id, 'main_name' => $main->name,
    'dmm_building' => $dmm->id, 'dmm_name' => $dmm->name, 'dmm_basement' => $dmmBasement->code, 'dmm_basement_name' => $dmmBasement->name,
];
echo "JSON:".json_encode($out, JSON_UNESCAPED_UNICODE)."\n";
