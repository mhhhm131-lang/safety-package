<?php
/**
 * تجهيز بوابة ٢٧-ب — للقاعدة المحلية وحدها (قرار ٥٧: لا بيانات تجريبية على المنشور؛ السكربت يرفض غير المحلي).
 * ينشئ حالة واحدة لكل بطاقة من بطاقات ٢٧-ب، كلها موسومة «بوابة ٢٧-ب»، ويطبع معرّفاتها JSON بعد السطر «JSON:».
 * التشغيل:  GATE27B=seed  TRIAL_PW=…  php artisan tinker tests/gates/seed-27b.php
 * التنظيف:  GATE27B=clean php artisan tinker tests/gates/seed-27b.php
 * كلمة حساب مدير الوحدة التجريبية من المتغيّر TRIAL_PW (لا تُكتب هنا).
 */

use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyEvaluation;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Risk\Models\Risk;
use App\Modules\Store\Models\InstituteDocument;
use App\Modules\Worker\Models\Worker;
use Illuminate\Support\Facades\DB;

if (!app()->environment('local') || DB::getDriverName() !== 'sqlite') {
    echo "مرفوض: التجهيز للقاعدة المحلية وحدها.\n";
    return;
}

$TAG = 'بوابة ٢٧-ب';
$like = '%'.$TAG.'%';
$mode = getenv('GATE27B') ?: 'seed';

// ── التنظيف أولاً دائماً: ما وُسم بالبوابة وحده ──
$riskIds = Risk::where('title', 'like', $like)->pluck('id');
foreach (['risk_phases', 'risk_events', 'risk_notes'] as $t) {
    if (DB::getSchemaBuilder()->hasTable($t)) DB::table($t)->whereIn('risk_id', $riskIds)->delete();
}
DB::table('app_notifications')->where('type', 'like', 'risk.%')->where('message', 'like', $like)->delete();
Risk::whereIn('id', $riskIds)->forceDelete();

$permitIds = DB::table('permits')->where('title', 'like', $like)->pluck('id');
PermitRequirement::whereIn('permit_id', $permitIds)->delete();
PermitDeviation::whereIn('permit_id', $permitIds)->delete();
foreach (['permit_events', 'permit_status_logs', 'permit_approvals'] as $t) {
    if (DB::getSchemaBuilder()->hasTable($t)) DB::table($t)->whereIn('permit_id', $permitIds)->delete();
}
DB::table('permits')->whereIn('id', $permitIds)->delete();

$partyIds = ExternalParty::where('name', 'like', $like)->pluck('id');
$projectIds = Project::where('name', 'like', $like)->pluck('id');
ExternalPartyEvaluation::whereIn('external_party_id', $partyIds)->delete();
ProjectContractor::whereIn('project_id', $projectIds)->delete();
Worker::where('full_name', 'like', $like)->forceDelete();
Project::whereIn('id', $projectIds)->forceDelete();
User::whereIn('external_party_id', $partyIds)->update(['external_party_id' => null]);
ExternalParty::whereIn('id', $partyIds)->forceDelete();

$formIds = FormTemplate::where('title', 'like', $like)->pluck('id');
FormAssignment::whereIn('form_id', $formIds)->delete();
FormField::whereIn('form_id', $formIds)->delete();
FormTemplate::whereIn('id', $formIds)->forceDelete();

foreach (User::where('username', 'like', 'gate27b.%')->get() as $u) {
    UserProfile::where('user_id', $u->id)->delete();
    DB::table('app_notifications')->where('user_id', $u->id)->delete();
    $u->delete();
}
OrganizationUnit::where('code', 'gate27b')->delete();

$doc = InstituteDocument::where('key', 'ipa-place')->first();
if ($doc) {
    $data = json_decode((string) $doc->data, true) ?: [];
    $ev = $data['HZ-07']['events'] ?? [];
    $kept = array_values(array_filter($ev, fn ($e) => !str_contains((string) ($e['name'] ?? ''), $TAG)));
    if (count($kept) !== count($ev)) {
        $data['HZ-07']['events'] = $kept;
        $doc->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }
}

if ($mode === 'clean') {
    echo "نُظّف ما وُسم «{$TAG}».\n";
    return;
}

// ── التجهيز ──
$pw = getenv('TRIAL_PW');
if (!$pw) { echo "TRIAL_PW غير مُمرَّرة.\n"; return; }
$id = fn (string $username) => User::where('username', $username)->value('id') ?? throw new RuntimeException("لا حساب: $username");
$out = [];

// المخاطر (قرار ٦٩): مسودة ومرفوعة في إدارة الاتصال المؤسسي بيد منسقها، وخطر عام مرفوع
$commUnit = UserProfile::where('user_id', $id('tj.comm.c'))->value('organization_unit_id');
$risk = fn (string $title, string $type, string $status, ?int $unit, int $by) => Risk::create(['title' => $title.' — '.$TAG, 'description' => 'وصف', 'risk_type' => $type,
    'status' => $status, 'severity' => 3, 'likelihood' => 3, 'created_by_id' => $by, 'organization_unit_id' => $unit])->id;
$out['risk_draft'] = $risk('انزلاق عند مدخل الإدارة', 'active', 'draft', $commUnit, $id('tj.comm.c'));
$out['risk_toreject'] = $risk('تحميل زائد على مقبس', 'active', 'draft', $commUnit, $id('tj.comm.c'));
$out['risk_general'] = $risk('انسكاب وقود المولد', 'reference', 'pending_approval', null, $id('munawib'));
$out['comm_unit'] = $commUnit;

// الطرف الخارجي ومشرفه، وطرف قيد التسجيل
$party = ExternalParty::create(['name' => 'مقاول التكييف — '.$TAG, 'party_type' => 'contractor', 'status' => 'active']);
User::where('username', 'tj.mushrif')->update(['external_party_id' => $party->id]);
$out['party'] = $party->id;
$out['party_pending'] = ExternalParty::create(['name' => 'مورّد قيد التسجيل — '.$TAG, 'party_type' => 'contractor', 'status' => 'pending'])->id;

// التصاريح: حالة لكل بطاقة
$typeId = PermitType::where('code', 'work_permit')->value('id');
$permit = function (string $key, string $status, array $extra = []) use ($typeId, $party, $id, $TAG) {
    return DB::table('permits')->insertGetId($extra + [
        'permit_type_id' => $typeId, 'permit_category' => PermitType::find($typeId)->category,
        'code' => 'ت-ب٢٧-'.$key, 'title' => 'صيانة وحدة التكييف ('.$key.') — '.$TAG, 'place_id' => Place::idByCode('HZ-06'),
        'external_party_id' => $party->id, 'requested_by_id' => $id('tj.mushrif'),
        'status' => $status, 'starts_at' => now()->addHour(), 'expires_at' => now()->addHours(8), 'created_at' => now(), 'updated_at' => now(),
    ]);
};
$out['permit_draft'] = $permit('draft', Permit::STATUS_DRAFT);
$out['permit_conditional'] = $permit('cond', Permit::STATUS_CONDITIONAL);
$out['permit_approved'] = $permit('appr', Permit::STATUS_APPROVED);
PermitRequirement::create(['permit_id' => $out['permit_approved'], 'category' => 'document', 'requirement_code' => 'doc-1', 'severity' => 'mandatory', 'status' => 'required']);
$out['permit_active'] = $permit('dev', Permit::STATUS_ACTIVE);
$out['deviation'] = PermitDeviation::create(['permit_id' => $out['permit_active'], 'description' => 'عمل بلا حزام أمان على السلم', 'severity' => 'high',
    'status' => PermitDeviation::STATUS_OPEN, 'recorded_by_id' => $id('tj.mushrif'), 'recorded_at' => now()])->id;
$out['permit_completed'] = $permit('done', Permit::STATUS_COMPLETED);

// عامل في التعريف، ومشروع اكتمل لم يُقيَّم طرفه
$out['worker'] = Worker::factory()->create(['status' => 'induction', 'full_name' => 'خالد العامل — '.$TAG])->id;
$project = Project::create(['name' => 'تجديد التكييف — '.$TAG, 'status' => 'completed', 'place_id' => Place::idByCode('HZ-06')]);
ProjectContractor::create(['project_id' => $project->id, 'external_party_id' => $party->id, 'role' => 'main', 'qualification_status' => ProjectContractor::STATUS_POST_APPROVED]);
$out['project'] = $project->id;

// نموذج أرسله مسؤول السلامة وفات موعده ولم يُذكَّر صاحبه
$form = FormTemplate::create(['title' => 'إقرار قراءة خطة الإخلاء — '.$TAG, 'is_active' => true, 'created_by_id' => $id('salama')]);
FormField::create(['form_id' => $form->id, 'label' => 'قرأتُ الخطة', 'field_type' => 'checkbox', 'is_required' => true, 'order' => 1]);
app(FormService::class)->assignToUsers($form, [$id('emp')], now()->addDays(3)->toDateString(), $id('salama'));
FormAssignment::where('form_id', $form->id)->update(['due_date' => now()->subDays(2)->toDateString()]);
$out['form'] = $form->id;

// وحدة بلا منسق سلامة ومديرها
$unit = OrganizationUnit::create(['name' => 'إدارة تجريبية — '.$TAG, 'code' => 'gate27b', 'unit_type' => 'department', 'parent_id' => null, 'is_active' => true]);
$mgr = User::create(['username' => 'gate27b.m', 'name' => 'مدير الإدارة التجريبية', 'password' => $pw, 'email' => 'gate27b.m@example.test']);
UserProfile::create(['user_id' => $mgr->id, 'role' => 'department_manager', 'is_active' => true, 'organization_unit_id' => $unit->id]);
$out['gap_unit'] = $unit->id;

// فعالية قادمة في القاعات رُشّح فريقها ولم يُعتمد
$doc = InstituteDocument::where('key', 'ipa-place')->first();
$data = $doc ? (json_decode((string) $doc->data, true) ?: []) : [];
$data['HZ-07'] = is_array($data['HZ-07'] ?? null) ? $data['HZ-07'] : ['plans' => new stdClass, 'units' => new stdClass];
$events = is_array($data['HZ-07']['events'] ?? null) ? $data['HZ-07']['events'] : [];
$events[] = ['name' => 'ملتقى القيادات — '.$TAG, 'date' => now()->addDays(5)->toDateString(), 'team' => [['name' => 'سعد المنسق']],
    'nom' => ['by' => 'مدير التدريب', 'date' => now()->toDateString()], 'appr' => []];
$data['HZ-07']['events'] = $events;
$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$doc ? $doc->update(['data' => $json]) : InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => $json]);
$out['event_index'] = count($events) - 1;

echo "JSON:".json_encode($out)."\n";
