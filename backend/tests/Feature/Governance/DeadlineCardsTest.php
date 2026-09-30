<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEquipment;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Services\MedicalProfileService;
use App\Modules\Emergency\Services\VisitorService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Project\Models\ContractorChannel;
use App\Modules\Project\Models\ContractorVerification;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Worker\Models\TrainingTopic;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerDocument;
use App\Modules\Worker\Models\WorkerTrainingRecord;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ٢٧-ج (قرار ٦٧، بكلمته «نعم مقبولة» ٢٠٢٦-٠٩-٣٠) — المواعيد: البطاقة تظهر قبل الموعد بسبعة أيام، تحمرّ بعده، وتختفي حين يُفعل.
 * لكل بطاقة: أبعد من سبعة أيام ← لا بطاقة؛ داخلها ← البطاقة لصاحبها وحده؛ بعد الموعد ← متأخرة؛ الفعل بمساره القائم ← تختفي.
 * خرجت بالقراءة (لا مسار يسجّلها فلا بطاقة تَعِد بما لا مخرج له): انتهاء صلاحية معدة الطوارئ، تدقيق المبنى، انتهاء تأهيل المقاول.
 */
class DeadlineCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $coord;
    private User $amn;
    private User $tabib;
    private User $mudir;
    private User $employee;
    private User $mushrif;
    private User $other;
    private ExternalParty $party;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, PermitTypesSeeder::class]);
        $this->building = EmergencyBuilding::main();
        $unit = OrganizationUnit::whereNotNull('place_id')->firstOrFail();
        $this->party = ExternalParty::create(['name' => 'مقاول التكييف', 'party_type' => 'contractor', 'status' => 'active', 'cr_number' => '1010123456']);
        $elsewhere = ExternalParty::create(['name' => 'مقاول آخر', 'party_type' => 'contractor', 'status' => 'active']);
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord = $this->user('coord', 'safety_coordinator', $unit->id);
        $this->amn = $this->user('amn', 'security_safety_head');
        $this->tabib = $this->user('tabib', 'clinic_doctor');
        $this->mudir = $this->user('mudir', 'department_manager', $unit->id);
        $this->employee = $this->user('emp', 'employee', $unit->id);
        $this->mushrif = $this->user('mushrif', 'contractor_supervisor', null, $this->party->id);
        $this->other = $this->user('other', 'contractor_supervisor', null, $elsewhere->id);
    }

    private function user(string $username, string $role, ?int $unitId = null, ?int $partyId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test", 'external_party_id' => $partyId]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function everyone(): array
    {
        return [$this->salama, $this->munawib, $this->coord, $this->amn, $this->tabib, $this->mudir, $this->employee, $this->mushrif, $this->other];
    }

    private function card(User $u, string $key): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => $t->key === $key);
    }

    /** البطاقة عند أصحابها وحدهم: كل من ليس في القائمة لا يراها */
    private function assertOwnersOnly(string $key, array $owners): void
    {
        $ids = array_map(fn (User $u) => $u->id, $owners);
        foreach ($this->everyone() as $u) {
            in_array($u->id, $ids, true)
                ? $this->assertNotNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» غائبة عن صاحبها")
                : $this->assertNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» تظهر لغير صاحبها");
        }
    }

    private function assertGone(string $key): void
    {
        $this->assertOwnersOnly($key, []);
    }

    /** ٣٤: تمرين مجدول ← من يملك التمارين، قبل موعده بسبعة أيام؛ يحمرّ بعده؛ يختفي حين يُلغى أو يبدأ */
    public function test_scheduled_drill_asks_its_owners_seven_days_ahead_and_turns_red_after(): void
    {
        $this->actingAs($this->coord)->post(route('emergency.drills.store'), ['building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-06'),
            'drill_type' => 'evacuation', 'scheduled_at' => now()->addDays(10)->toDateTimeString()])->assertRedirect();
        $drill = EvacuationDrill::firstOrFail();
        $key = "drill:{$drill->id}";
        $this->assertGone($key); // أبعد من سبعة أيام

        $drill->update(['scheduled_at' => now()->addDays(5)]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord]);
        $t = $this->card($this->coord, $key);
        $this->assertFalse($t->isOverdue);
        $this->assertStringContainsString('إخلاء', $t->question);
        $this->assertStringContainsString(Place::where('code', 'HZ-06')->value('name'), $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $drill->update(['scheduled_at' => now()->subHour()]);
        $t = $this->card($this->coord, $key);
        $this->assertTrue($t->isOverdue);
        $this->assertStringContainsString('فات موعده', $t->question);

        $this->actingAs($this->coord)->post(route('emergency.drills.cancel', $drill), ['reason' => 'تعارض'])->assertRedirect();
        $this->assertGone($key);
    }

    /** ٣٥: معدة طوارئ حان فحصها الدوري ← من يملك المعدات؛ «سُجّل الفحص» يبعد الموعد فتختفي */
    public function test_emergency_equipment_due_for_inspection_asks_its_owners_until_inspected(): void
    {
        $store = fn (string $code, $last) => $this->actingAs($this->coord)->post(route('emergency.equipment.store'), ['building_id' => $this->building->id,
            'place_id' => Place::idByCode('HZ-06'), 'equipment_type' => 'fire_extinguisher', 'code' => $code, 'inspection_frequency' => 'monthly',
            'last_inspection_date' => $last->toDateString()])->assertRedirect();
        $store('FE-FAR', now());             // الفحص القادم بعد ٣٠ يوماً
        $store('FE-SOON', now()->subDays(25)); // بعد خمسة أيام
        $far = EmergencyEquipment::where('code', 'FE-FAR')->firstOrFail();
        $soon = EmergencyEquipment::where('code', 'FE-SOON')->firstOrFail();
        $this->assertGone("eqinspect:{$far->id}");
        $key = "eqinspect:{$soon->id}";
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord]);
        $t = $this->card($this->coord, $key);
        $this->assertFalse($t->isOverdue);
        $this->assertStringContainsString('FE-SOON', $t->item ?? $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk()->assertSee('FE-SOON');

        $soon->update(['next_inspection_date' => now()->subDays(2)]);
        $this->assertTrue($this->card($this->coord, $key)->isOverdue);

        $this->actingAs($this->coord)->post(route('emergency.equipment.inspect', $soon), ['result' => 'pass'])->assertRedirect();
        $this->assertGone($key);
    }

    /** ٣٩: زائر فات موعد خروجه ولم يُسجَّل خروجه ← رئيس الأمن والسلامة والمركز، بطاقة واحدة بالعدد */
    public function test_visitors_past_their_checkout_ask_security_and_the_center_in_one_card(): void
    {
        $svc = app(VisitorService::class);
        $a = $svc->checkIn(['building_id' => $this->building->id, 'name' => 'زائر أول', 'expected_checkout_at' => now()->addHour()]);
        $b = $svc->checkIn(['building_id' => $this->building->id, 'name' => 'زائر ثانٍ']); // بلا موعد، دخل اليوم
        $key = "visitors:{$this->building->id}";
        $this->assertGone($key);

        $a->update(['expected_checkout_at' => now()->subHour()]);
        $this->assertOwnersOnly($key, [$this->amn, $this->salama, $this->munawib]);
        $t = $this->card($this->amn, $key);
        $this->assertStringContainsString(': 1', $t->question);
        $this->assertTrue($t->isOverdue);
        $this->actingAs($this->amn)->get($t->primary['url'])->assertOk()->assertSee('زائر أول');

        // دخل أمس بلا موعد خروج ولم يخرج ← يُعدّ
        DB::table('emergency_visitors')->where('id', $b->id)->update(['checked_in_at' => now()->subDay()]);
        $this->assertStringContainsString(': 2', $this->card($this->amn, $key)->question);

        $this->actingAs($this->amn)->postJson("/api/emergency/visitors/{$a->id}/check-out")->assertOk();
        $this->actingAs($this->amn)->postJson("/api/emergency/visitors/{$b->id}/check-out")->assertOk();
        $this->assertGone($key);
    }

    /** ٤٠: ملفات طبية لم تُراجع (العدّ نفسه الذي في لوحة الطبيب) ← طبيب العيادة وحده */
    public function test_medical_profiles_needing_review_ask_the_clinic_doctor_with_the_dashboard_count(): void
    {
        $this->assertGone('medreview');
        $svc = app(MedicalProfileService::class);
        $p1 = $svc->getOrCreate($this->employee->id);
        $p2 = $svc->getOrCreate($this->mudir->id);
        $this->assertOwnersOnly('medreview', [$this->tabib]);
        $t = $this->card($this->tabib, 'medreview');
        $this->assertStringContainsString(': '.$svc->getProfilesNeedingReview()->count(), $t->question);
        $this->assertStringContainsString(': 2', $t->question);
        $this->actingAs($this->tabib)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->tabib)->postJson("/api/emergency/medical/{$p1->id}/reviewed")->assertOk();
        $this->assertStringContainsString(': 1', $this->card($this->tabib, 'medreview')->question);
        $this->actingAs($this->tabib)->postJson("/api/emergency/medical/{$p2->id}/reviewed")->assertOk();
        $this->assertGone('medreview');
    }

    private function permit(string $status, $expires): Permit
    {
        $typeId = PermitType::where('code', 'work_permit')->value('id');
        $id = DB::table('permits')->insertGetId([
            'permit_type_id' => $typeId, 'permit_category' => PermitType::find($typeId)->category,
            'code' => 'ت-اختبار-'.random_int(1000, 9999), 'title' => 'صيانة وحدة التكييف', 'place_id' => Place::idByCode('HZ-06'),
            'external_party_id' => $this->party->id, 'requested_by_id' => $this->mushrif->id,
            'status' => $status, 'starts_at' => now()->subHour(), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Permit::findOrFail($id);
    }

    /** ٥٥: تصريح نشط تقترب نهايته ← من يغلقه؛ يحمرّ بعد انتهاء مدته؛ يختفي حين يُغلق أو يُنهيه النظام */
    public function test_active_permit_near_its_end_asks_those_who_close_it(): void
    {
        $far = $this->permit(Permit::STATUS_ACTIVE, now()->addDays(10));
        $this->assertGone("permit:{$far->id}:expiring");
        $approved = $this->permit(Permit::STATUS_APPROVED, now()->addDays(2)); // لم يُفعَّل: بطاقته «فعّله» لا هذه
        $this->assertGone("permit:{$approved->id}:expiring");

        $p = $this->permit(Permit::STATUS_ACTIVE, now()->addDays(3));
        $key = "permit:{$p->id}:expiring";
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord]);
        $t = $this->card($this->coord, $key);
        $this->assertFalse($t->isOverdue);
        $this->assertStringContainsString($p->code, $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $p->update(['expires_at' => now()->subHour()]);
        $t = $this->card($this->coord, $key);
        $this->assertTrue($t->isOverdue);
        $this->assertStringContainsString('انتهت مدته', $t->question);

        $this->artisan('permits:expire-overdue')->assertSuccessful();
        $this->assertSame(Permit::STATUS_EXPIRED, $p->fresh()->status);
        $this->assertGone($key);
    }

    /** ٥٩: معدة (تصاريح) حان فحصها ← من يدير المعدات؛ تسجيل الفحص يبعد الموعد فتختفي؛ المستبعدة لا تُسأل */
    public function test_permit_equipment_due_for_inspection_asks_its_managers_until_inspected(): void
    {
        $eq = Equipment::create(['name' => 'رافعة شوكية', 'code' => 'FL-1', 'equipment_type' => 'lifting', 'status' => 'active',
            'place_id' => Place::idByCode('HZ-08'), 'inspection_frequency_days' => 90, 'next_inspection_date' => now()->addDays(10)]);
        $key = "epceq:{$eq->id}";
        $this->assertGone($key);

        $eq->update(['next_inspection_date' => now()->addDays(3)]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord]);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('رافعة شوكية', $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $eq->update(['status' => 'retired']);
        $this->assertGone($key);
        $eq->update(['status' => 'active', 'next_inspection_date' => now()->subDays(2)]);
        $this->assertTrue($this->card($this->coord, $key)->isOverdue);

        $this->actingAs($this->coord)->post("/app/equipment/{$eq->id}/inspections", ['inspection_date' => now()->toDateString(), 'result' => 'pass'])->assertRedirect();
        $this->assertGone($key);
    }

    /** ٦٥: وثيقة طرف خارجي موثّقة تقترب نهايتها ← المركز وحساب الطرف نفسه؛ وثيقة أحدث من النوع نفسه تُخفيها */
    public function test_party_document_near_expiry_asks_the_center_and_the_party_until_replaced(): void
    {
        $doc = fn ($expiry, bool $verified = true) => ExternalPartyDocument::create(['external_party_id' => $this->party->id, 'name' => 'التأمين', 'document_type' => 'insurance',
            'file' => 'ins.pdf', 'is_verified' => $verified, 'expiry_date' => $expiry, 'uploaded_by_id' => $this->salama->id]);
        $d = $doc(now()->addDays(10));
        $key = "epdoc:{$d->id}:expiry";
        $this->assertGone($key);

        $d->update(['expiry_date' => now()->addDays(3)]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->mushrif]);
        $this->assertStringContainsString('مقاول التكييف', $this->card($this->salama, $key)->question);
        $this->actingAs($this->salama)->get($this->card($this->salama, $key)->primary['url'])->assertOk();
        $this->actingAs($this->mushrif)->get($this->card($this->mushrif, $key)->primary['url'])->assertOk();

        $d->update(['expiry_date' => now()->subDays(2)]);
        $this->assertTrue($this->card($this->salama, $key)->isOverdue);

        // المقاول يرفع وثيقة جديدة من النوع نفسه ← القديمة لا تُسأل، والجديدة تنتظر التحقق ببطاقتها القائمة
        $new = $doc(now()->addYear(), false);
        $this->assertGone($key);
        $this->assertNotNull($this->card($this->salama, "epdoc:{$new->id}:verify"));
    }

    /** ٦٦: وثيقة عامل تقترب نهايتها ← من يعدّل العمال، والمشرف لعمال طرفه وحدهم؛ وثيقة أحدث تُخفيها */
    public function test_worker_document_near_expiry_asks_those_who_edit_workers_within_their_party(): void
    {
        $w = Worker::factory()->create(['external_party_id' => $this->party->id, 'status' => 'approved', 'full_name' => 'خالد العامل']);
        $doc = fn ($expiry) => WorkerDocument::create(['worker_id' => $w->id, 'document_type' => 'safety_certificate', 'name' => 'شهادة سلامة', 'expiry_date' => $expiry, 'created_at' => now()]);
        $d = $doc(now()->addDays(10));
        $key = "wdoc:{$d->id}:expiry";
        $this->assertGone($key);

        $d->update(['expiry_date' => now()->addDays(3)]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord, $this->mushrif]);
        $t = $this->card($this->mushrif, $key);
        $this->assertStringContainsString('خالد العامل', $t->question);
        $this->actingAs($this->mushrif)->get($t->primary['url'])->assertOk();

        $d->update(['expiry_date' => now()->subDays(2)]);
        $this->assertTrue($this->card($this->coord, $key)->isOverdue);

        $w->update(['status' => 'blocked']);
        $this->assertGone($key); // المحظور لا يُطلب تجديد وثائقه
        $w->update(['status' => 'approved']);
        $doc(now()->addYear());
        $this->assertGone($key);
    }

    /** ٦٧: تدريب عامل تقترب نهايته ← من يسجّل التدريب؛ سجل أحدث للموضوع نفسه يُخفيها */
    public function test_worker_training_near_expiry_asks_those_who_record_training(): void
    {
        $w = Worker::factory()->create(['external_party_id' => $this->party->id, 'status' => 'approved', 'full_name' => 'خالد العامل']);
        $topic = TrainingTopic::create(['name' => 'سلامة السقالات', 'code' => 'SCAF-01', 'category' => 'high_risk']);
        $r = WorkerTrainingRecord::create(['worker_id' => $w->id, 'training_topic_id' => $topic->id, 'status' => 'completed', 'completed_at' => now()->subYear(), 'expires_at' => now()->addDays(10)]);
        $key = "wtrain:{$r->id}:expiry";
        $this->assertGone($key);

        $r->update(['expires_at' => now()->addDays(3)]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib, $this->coord]);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('سلامة السقالات', $t->question);
        $this->assertStringContainsString('خالد العامل', $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->coord)->post(route('workers.training.store', $w), ['training_topic_id' => $topic->id, 'status' => 'completed',
            'completed_at' => now()->toDateString(), 'expires_at' => now()->addYear()->toDateString()])->assertRedirect();
        $this->assertGone($key);
    }

    /** ٦٨: تحقق مقاول من قناة مفعّلة انتهى ← المركز: «أعد التحقق» بضغطة؛ القناة المعطّلة لا تُسأل (لا مخرج لها) */
    public function test_expired_contractor_verification_asks_the_center_to_reverify_in_one_press(): void
    {
        $v = ContractorVerification::create(['external_party_id' => $this->party->id, 'field_name' => 'gosi_account_number', 'field_value' => '55',
            'source_channel' => ContractorChannel::CHANNEL_GOSI, 'confidence_score' => 80, 'verified_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);
        $key = "cverify:{$this->party->id}:".ContractorChannel::CHANNEL_GOSI;
        $this->assertGone($key); // القناة غير مفعّلة

        ContractorChannel::create(['channel_type' => ContractorChannel::CHANNEL_GOSI, 'enabled' => true, 'priority' => 10,
            'config_json' => ['api_key' => 'k', 'base_url' => 'https://gosi.test']]);
        $this->assertOwnersOnly($key, [$this->salama, $this->munawib]);
        $t = $this->card($this->salama, $key);
        $this->assertTrue($t->isOverdue);
        $this->assertSame('POST', $t->primaryMethod());
        $this->assertStringContainsString('مقاول التكييف', $t->question);

        Http::fake(['gosi.test/*' => Http::response(['account_number' => '77'])]);
        $this->actingAs($this->salama)->post($t->primary['url'])->assertRedirect();
        $this->assertTrue($v->fresh()->expires_at->isFuture());
        $this->assertGone($key);
    }
}
