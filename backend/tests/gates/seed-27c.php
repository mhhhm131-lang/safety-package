<?php
/**
 * تجهيز بوابة ٢٧-ج (المواعيد) — للقاعدة المحلية وحدها (قرار ٥٧؛ السكربت يرفض غير المحلي).
 * ينشئ موعداً قريباً لكل بطاقة، كلها موسومة «بوابة ٢٧-ج» أو بالرمز G27C، ويطبع معرّفاتها JSON بعد «JSON:».
 * التشغيل:  GATE27C=seed  php artisan tinker tests/gates/seed-27c.php
 * التنظيف:  GATE27C=clean php artisan tinker tests/gates/seed-27c.php
 */

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EmergencyEquipmentInspection;
use App\Modules\Emergency\Models\EmergencyVisitor;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Worker\Models\TrainingTopic;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerDocument;
use App\Modules\Worker\Models\WorkerTrainingRecord;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٢٧-ج';
$like = '%'.$TAG.'%';
$mode = getenv('GATE27C') ?: 'seed';
$has = fn (string $t) => DB::getSchemaBuilder()->hasTable($t);

// ── التنظيف أولاً دائماً: ما وُسم بالبوابة وحده ──
$drillIds = EvacuationDrill::where('scenario', 'like', $like)->pluck('id');
if ($has('evacuation_drill_participants')) DB::table('evacuation_drill_participants')->whereIn('drill_id', $drillIds)->delete();
EvacuationDrill::whereIn('id', $drillIds)->delete();

$eqIds = EmergencyEquipment::where('code', 'like', 'G27C-%')->pluck('id');
EmergencyEquipmentInspection::whereIn('equipment_id', $eqIds)->delete();
EmergencyEquipment::whereIn('id', $eqIds)->delete();

$visitorIds = EmergencyVisitor::where('name', 'like', $like)->pluck('id');
if ($has('emergency_visitor_logs')) DB::table('emergency_visitor_logs')->whereIn('visitor_id', $visitorIds)->delete();
EmergencyVisitor::whereIn('id', $visitorIds)->delete();

$permitIds = DB::table('permits')->where('title', 'like', $like)->pluck('id');
foreach (['permit_events', 'permit_requirements', 'permit_deviations'] as $t) {
    if ($has($t)) DB::table($t)->whereIn('permit_id', $permitIds)->delete();
}
DB::table('permits')->whereIn('id', $permitIds)->delete();

$epcIds = Equipment::where('code', 'like', 'G27C-%')->pluck('id');
if ($has('equipment_inspections')) DB::table('equipment_inspections')->whereIn('equipment_id', $epcIds)->delete();
Equipment::whereIn('id', $epcIds)->delete();

$workerIds = Worker::where('full_name', 'like', $like)->pluck('id');
WorkerDocument::whereIn('worker_id', $workerIds)->delete();
WorkerTrainingRecord::whereIn('worker_id', $workerIds)->delete();
Worker::whereIn('id', $workerIds)->delete();

$partyIds = ExternalParty::where('name', 'like', $like)->pluck('id');
ExternalPartyDocument::whereIn('external_party_id', $partyIds)->delete();
User::whereIn('external_party_id', $partyIds)->update(['external_party_id' => null]);
ExternalParty::whereIn('id', $partyIds)->delete();

if ($mode === 'clean') {
    echo "نُظّف ما وُسم «{$TAG}».\n";
    return;
}

// ── التجهيز ──
$id = fn (string $username) => User::where('username', $username)->value('id') ?? throw new RuntimeException("لا حساب: $username");
$building = EmergencyBuilding::main();
$hz6 = Place::idByCode('HZ-06');
$out = ['building' => $building->id];

// تمرين بعد ثلاثة أيام
$out['drill'] = EvacuationDrill::create(['building_id' => $building->id, 'place_id' => $hz6, 'drill_type' => 'evacuation', 'scheduled_at' => now()->addDays(3),
    'scenario' => $TAG, 'status' => 'scheduled'])->id;

// معدتا طوارئ في المكاتب: واحدة بعد يومين وواحدة فات موعدها أمس
$eq = fn (string $code, $next) => EmergencyEquipment::create(['building_id' => $building->id, 'place_id' => $hz6, 'equipment_type' => 'fire_extinguisher', 'code' => $code,
    'inspection_frequency' => 'monthly', 'last_inspection_date' => now()->subDays(28), 'next_inspection_date' => $next, 'status' => 'operational'])->id;
$out['eq_soon'] = $eq('G27C-1', now()->addDays(2));
$out['eq_late'] = $eq('G27C-2', now()->subDay());

// زائر فات موعد خروجه
$out['visitor'] = EmergencyVisitor::create(['building_id' => $building->id, 'place_id' => $hz6, 'name' => 'زائر — '.$TAG, 'badge_number' => 'G27C',
    'expected_checkout_at' => now()->subHour(), 'qr_token' => \Illuminate\Support\Str::random(64)])->id;

// طرف ومشرفه، وتصريح نشط ينتهي بعد يومين
$party = ExternalParty::create(['name' => 'مقاول التكييف — '.$TAG, 'party_type' => 'contractor', 'status' => 'active']);
User::where('username', 'tj.mushrif')->update(['external_party_id' => $party->id]);
$out['party'] = $party->id;
$typeId = PermitType::where('code', 'work_permit')->value('id');
$out['permit'] = DB::table('permits')->insertGetId([
    'permit_type_id' => $typeId, 'permit_category' => PermitType::find($typeId)->category,
    'code' => 'ت-ج٢٧-1', 'title' => 'صيانة وحدة التكييف — '.$TAG, 'place_id' => $hz6,
    'external_party_id' => $party->id, 'requested_by_id' => $id('tj.mushrif'),
    'status' => Permit::STATUS_ACTIVE, 'starts_at' => now()->subHour(), 'expires_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now(),
]);

// معدة تصاريح حان فحصها بعد يومين
$out['epc'] = Equipment::create(['name' => 'رافعة شوكية', 'code' => 'G27C-EQ', 'equipment_type' => 'lifting', 'status' => 'active',
    'place_id' => Place::idByCode('HZ-08'), 'inspection_frequency_days' => 90, 'next_inspection_date' => now()->addDays(2)])->id;

// وثيقة الطرف الموثّقة، ووثيقة عامله، وتدريبه — كلها تنتهي بعد ثلاثة أيام
$out['epdoc'] = ExternalPartyDocument::create(['external_party_id' => $party->id, 'name' => 'التأمين', 'document_type' => 'insurance', 'file' => 'ins.pdf',
    'is_verified' => true, 'expiry_date' => now()->addDays(3), 'uploaded_by_id' => $id('salama')])->id;
$worker = Worker::factory()->create(['external_party_id' => $party->id, 'status' => 'approved', 'full_name' => 'خالد العامل — '.$TAG]);
$out['worker'] = $worker->id;
$out['wdoc'] = WorkerDocument::create(['worker_id' => $worker->id, 'document_type' => 'safety_certificate', 'name' => 'شهادة سلامة', 'expiry_date' => now()->addDays(3), 'created_at' => now()])->id;
$topic = TrainingTopic::firstOrFail();
$out['topic'] = $topic->id;
$out['wtrain'] = WorkerTrainingRecord::create(['worker_id' => $worker->id, 'training_topic_id' => $topic->id, 'status' => 'completed',
    'completed_at' => now()->subYear(), 'expires_at' => now()->addDays(3)])->id;

echo "JSON:".json_encode($out)."\n";
