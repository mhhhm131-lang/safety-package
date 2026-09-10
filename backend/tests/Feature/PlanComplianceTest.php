<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EvacuationDrill;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * المرحلة ١٠-٤ — التقرير والمؤشرات والتمارين والجاهزية (قرار ٣٠، المكوّن ز). البوابة:
 *   تمرين HZ-07 ينتج جدول الالتزام بالخطة؛ لوحة التقارير تعرض أزمنة الخطوات الثلاث لكل مكان؛ مركز الطوارئ يعرض بند الخطة.
 */
class PlanComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $coord; private User $marafiq; private User $exec; private User $fani;
    private EmergencyBuilding $building;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(base_path());
        if (!is_file($root.'/HZ-07-halls/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة');
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);
        app(ResponsePlanSync::class)->sync($root);
        $this->salama = $this->user('salama', 'system_admin');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-07');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
        $this->exec = $this->user('idara', 'top_management');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-07');
        $this->building = EmergencyBuilding::main();
        // فريق أولي للقاعات (المحاضر = منسق + مسعف) من ملف المكان
        $unit = OrganizationUnit::first();
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-07' => ['plans' => [], 'units' => ['_' => [
            'team' => [['role' => 'المنسق', 'name' => 'محاضر القاعة', 'dept' => 'التدريب', 'phone' => '0500000007', 'trained' => '', 'trainer' => ''],
                ['role' => 'المسعف', 'name' => 'مسعف القاعة', 'dept' => 'التدريب', 'phone' => '0500000008', 'trained' => '', 'trainer' => '']],
            'nom' => ['by' => 'x', 'dept' => $unit->code, 'date' => '2026-03-01'], 'appr' => ['by' => 'y', 'date' => '2026-03-05'], 'hr' => [], 'dept' => $unit->code,
        ]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_gate_drill_in_halls_yields_plan_compliance_table_reports_and_readiness(): void
    {
        // ١) تمرين إخلاء في القاعات يبدأ فتُنسخ خطوات HZ-07 (٣ طبي + ١٠ حريق) بالمسطرة نفسها
        $this->actingAs($this->salama)->post('/app/emergency/drills', ['building_id' => $this->building->id, 'place_id' => Place::idByCode('HZ-07'), 'drill_type' => 'fire',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'scenario' => 'حريق في قاعة', 'target_time_sec' => 300])->assertRedirect();
        $drill = EvacuationDrill::first();
        $this->actingAs($this->coord)->post("/app/emergency/drills/{$drill->id}/start")->assertRedirect();
        $i = $drill->fresh()->incident;
        $this->assertTrue($i->is_drill);
        $this->assertSame(13, $i->planSteps()->count());

        // وصول المسعف بعد ٣ ث ← التدخل الأولي ×٢ ضمن النافذة؛ «تم» على استدعاء الطبيب بعد ٤٠ ث (تأخر)؛ وصول الطبيب بعد ٩٠ ث (ضمن ١–٣ د)
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(3));
        $medic = $i->checkIns()->where('person_type', 'team')->whereHas('teamMember', fn ($q) => $q->where('role_key', 'medic'))->first();
        $this->actingAs($this->coord)->post("/app/emergency/incidents/{$i->id}/check-in", ['check_in_id' => $medic->id])->assertSessionHas('success');
        $call = $i->planSteps()->where('path_key', 'medical')->where('label', '٢')->first();
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(40));
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$i->id}/steps/{$call->id}/done")->assertSessionHas('success');
        $arrive = $i->planSteps()->where('path_key', 'medical')->where('label', '٣')->first();
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(90));
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$i->id}/steps/{$arrive->id}/done")->assertSessionHas('success');
        $evac = $i->planSteps()->where('path_key', 'fire')->where('title', 'قرار إخلاء المبنى الكامل')->first();
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$i->id}/steps/{$evac->id}/skip", ['note' => 'تمرين جزئي'])->assertSessionHas('success');
        Carbon::setTestNow($i->triggered_at->copy()->addSeconds(400));
        $this->actingAs($this->coord)->post("/app/emergency/drills/{$drill->id}/end", ['observations' => 'تأخر استدعاء الطبيب'])->assertRedirect('/app/emergency/drills');
        $this->assertSame('ended', $i->fresh()->status);

        // ٢) تقرير ما بعد الحدث: جدول الالتزام بالخطة بالخطوة والمستهدف والفعلي والفارق ومن
        $r = $this->actingAs($this->exec)->get("/app/emergency/incidents/{$i->id}/report");
        $r->assertOk()->assertSee('الالتزام بالخطة')->assertSee('id="planCompliance"', false)
            ->assertSee('استدعاء الطبيب')->assertSee('بعد 40 ث')->assertSee('تأخر 30 ث')       // المستهدف ٥–١٠ ث في HZ-07
            ->assertSee('وصول الطبيب')->assertSee('بعد 1:30 د')->assertSee('ضمن النافذة (قبل الحد بـ 1:30 د)')
            ->assertSee('آلياً من وصول عضو الفريق الأولي')->assertSee('لم تُعلَّم')->assertSee('تمرين جزئي')
            ->assertSee('ضمن النافذة 3')->assertSee('تأخرت 1')->assertSee('تُخطّيت 1')->assertSee('شرطية تمت 1');
        $rows = substr_count($r->getContent(), 'data-state="');
        $this->assertSame(13, $rows);
        // ٣ ضمن النافذة (التدخل ×٢، وصول الطبيب) + ١ تأخر + ٧ لم تُعلَّم = ١١ مقيسة؛ الاستعادة شرطية تمت آلياً عند الإنهاء (لا تُقاس)؛ الإخلاء تُخطّي
        $this->assertSame(0, substr_count($r->getContent(), 'data-state="conditional"'));
        $this->assertSame(1, substr_count($r->getContent(), 'data-state="done_conditional"'));
        $this->assertSame(7, substr_count($r->getContent(), 'data-state="missed"'));
        $this->assertStringContainsString('data-plan-ratio="27"', $r->getContent()); // ٣ من ١١ مقيسة

        // ٣) شاشة التمارين تعرض شارة الالتزام بالخطة برابط التقرير
        $this->actingAs($this->salama)->get('/app/emergency/drills')->assertOk()->assertSee('الخطة: 3/11 ضمن النافذة')->assertSee('#planCompliance');

        // ٤) لوحة التقارير: أزمنة الخطوات الثلاث لكل مكان — HZ-07 بأرقامها والبقية «لا بيانات»
        $r = $this->actingAs($this->exec)->get('/app/reports');
        $r->assertOk()->assertSee('خطوات الاستجابة الثلاث الأولى لكل مكان')->assertSee('data-plan-place="HZ-07"', false);
        $html = $r->getContent();
        $row = substr($html, strpos($html, 'data-plan-place="HZ-07"'));
        $row = substr($row, 0, strpos($row, '</tr>'));
        $this->assertStringContainsString('3 ث / 3 ث / 5 ث', $row);      // التدخل الأولي: متوسط / أطول / المستهدف
        $this->assertStringContainsString('40 ث / 40 ث / 10 ث', $row);   // استدعاء الطبيب متأخر
        $this->assertStringContainsString('1:30 د / 1:30 د / 3:00 د', $row);
        $this->assertStringContainsString('3/11 (27٪)', $row);
        $this->assertStringContainsString('<td class="text-center">1</td>', $row); // تمرين واحد
        $row06 = substr($html, strpos($html, 'data-plan-place="HZ-06"'));
        $this->assertStringContainsString('لا بيانات', substr($row06, 0, strpos($row06, '</tr>')));
        $this->assertStringContainsString('data-trend="'.now()->format('Y-m').'"', $html);
        $this->actingAs($this->fani)->get('/app/reports')->assertForbidden();

        // ٥) مركز الطوارئ: بند الخطة في جاهزية كل مكان
        $r = $this->actingAs($this->salama)->get('/app/emergency');
        $r->assertOk()->assertSee('خطة الاستجابة')->assertSee('مزامَنة من الوثيقة: 13 خطوة')->assertSee('مزامَنة من الوثيقة: 12 خطوة')->assertSee('مركز القيادة — بلا خطة استجابة');
        $html = $r->getContent();
        $cell07 = substr($html, strpos($html, 'data-plan="HZ-07"')); $cell07 = substr($cell07, 0, strpos($cell07, '</td>'));
        // HZ-07: المحاضر (٦) والمنسق والمسعف لهم شاغل من ملف المكان؛ بلا شاغل: رئيس الأمن (٣) والطبيب/الإسناد (٤، ٥، ١٤–١٩) وقائد الطوارئ (١) والمناوب (٢١) والمنقذ والإطفائي (١٠، ١١)
        $this->assertStringContainsString('أدوار بلا شاغل:', $cell07);
        $this->assertStringContainsString('بطاقات: 1، 3، 4، 5، 10، 11', $cell07);
        $this->assertStringNotContainsString('، 2،', $cell07); // مدير المرافق له حساب
        $this->assertStringNotContainsString('، 6،', $cell07); // المحاضر له شاغل
        $cell06 = substr($html, strpos($html, 'data-plan="HZ-06"')); $cell06 = substr($cell06, 0, strpos($cell06, '</td>'));
        $this->assertStringContainsString('أدوار بلا شاغل:', $cell06); // لا فريق أولي في HZ-06
        $this->assertStringContainsString('8، 9، 10، 11', $cell06);
    }
}
