<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * المرحلة ١٠-١ — المزامنة والأدوار (BACKEND.md قرار ٣٠، المكوّنان أ وب). البوابة:
 *   الخطط الثماني مزامَنة، وعدد خطوات كل مسار يطابق رأس المسار في الوثيقة حرفياً، وكل خطوة لها بطاقة دور
 *   (الاستثناء المرصود: «اللجنة الفنية» ليست بطاقة — يُبلَّغ المستخدم)، وتعديل تجريبي في نسخة من وثيقة يُعاد قراءته بلا كتابة عكسية.
 */
class ResponsePlanTest extends TestCase
{
    use RefreshDatabase;

    /** الأرقام المعلنة في رؤوس الوثائق كما هي (البوابة ١٠-١) — تُقرأ ولا تُحسب. */
    private const DECLARED = ['HZ-01' => 21, 'HZ-02' => 19, 'HZ-03' => 19, 'HZ-04' => 22, 'HZ-05' => 19, 'HZ-06' => 16, 'HZ-07' => 17, 'HZ-08' => 13];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->root = dirname(base_path());
        if (!is_file($this->root.'/HZ-06-offices/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة في المجلد الأعلى');
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function plan(string $code): ResponsePlan
    {
        return ResponsePlan::whereHas('place', fn ($q) => $q->where('code', $code))->with('steps')->firstOrFail();
    }

    public function test_sync_reads_eight_plans_and_path_counts_match_document_headers(): void
    {
        $r = app(ResponsePlanSync::class)->sync($this->root);
        $this->assertCount(8, $r);
        $this->assertSame(8, ResponsePlan::count());
        foreach ($r as $code => $x) $this->assertSame('synced', $x['status'], $code);

        foreach (self::DECLARED as $code => $declared) {
            $plan = $this->plan($code);
            $this->assertSame($declared, $plan->declared_total, "$code: الرقم المعلن في رأس الوثيقة");
            // كل مسار له رأس «N خطوة»: المشتق يساويه حرفياً
            foreach ($plan->steps->whereNotNull('path_declared_count')->groupBy('path_key') as $key => $steps) {
                $this->assertSame($steps->first()->path_declared_count, $steps->count(), "$code/$key: رأس المسار مقابل المشتق");
            }
            $this->assertSame($plan->steps->whereIn('path_key', ResponsePlan::LIVE_PATHS)->count(), $plan->steps_count);
        }

        // HZ-06: ٣ طبي + ٩ حريق = ١٢ خطوة حية (بوابة ١٠-٢) + ٤ بنود كشف = ١٦ المعلن
        $hz06 = $this->plan('HZ-06');
        $this->assertSame(12, $hz06->steps_count);
        $this->assertSame(4, $hz06->detection_count);
        $this->assertSame(16, $hz06->steps_count + $hz06->detection_count);
        $this->assertSame(['medical' => 3, 'fire' => 9], $hz06->pathSteps()->groupBy('path_key')->map->count()->all());
        // HZ-04: ٣ طبي + ١٤ حريق + ٣ حالات أخرى + سيناريوان
        $hz04 = $this->plan('HZ-04');
        $this->assertSame(20, $hz04->steps_count);
        $this->assertSame(2, $hz04->scenario_count);
        $this->assertSame(14, $hz04->steps->where('path_key', 'fire')->count());
    }

    public function test_steps_carry_when_window_who_card_where_how(): void
    {
        app(ResponsePlanSync::class)->sync($this->root);
        $fire = $this->plan('HZ-06')->steps->where('path_key', 'fire')->values();

        $s3 = $fire[2]; // التحكم بالأنظمة الحرجة — مدير المرافق يوجّه فريق التحكم — الثانية الأولى
        $this->assertSame('٣', $s3->label);
        $this->assertSame('التحكم بالأنظمة الحرجة', $s3->title);
        $this->assertSame('الثانية الأولى', $s3->when_text);
        $this->assertSame([0, 5], [$s3->window_from_sec, $s3->window_to_sec]);
        $this->assertFalse($s3->is_conditional);
        $this->assertSame('مدير المرافق يوجّه فريق التحكم', $s3->who_text);
        $this->assertSame(2, $s3->role_card_no);
        $this->assertSame([2, 14, 15, 16, 17, 18, 19], $s3->role_cards);
        $this->assertSame('مكان التحكم', $s3->where_text);
        $this->assertSame('سحب الدخان + التكييف + الكهرباء + المصاعد.', $s3->how_text);

        $this->assertSame([0, 60], [$fire[4]->window_from_sec, $fire[4]->window_to_sec]);   // ٠–٦٠ ثانية
        $this->assertSame(1, $fire[4]->role_card_no);                                         // قائد فريق الطوارئ
        $this->assertTrue($fire[6]->is_conditional);                                          // عند عدم السيطرة
        $this->assertNull($fire[6]->window_from_sec);
        $this->assertSame([300, 900], [$fire[7]->window_from_sec, $fire[7]->window_to_sec]); // ٥–١٥ دقيقة
        $this->assertSame(3, $fire[7]->role_card_no);                                         // رئيس قسم الأمن
        $this->assertSame([1, 2, 3], $fire[3]->role_cards);                                   // قائد الطوارئ + مدير المرافق + رئيس الأمن

        $med = $this->plan('HZ-06')->steps->where('path_key', 'medical')->values();
        $this->assertSame([8, 9, 10, 11], $med[0]->role_cards);  // فريق التدخل الأولي
        $this->assertSame(21, $med[1]->role_card_no);            // مناوب الأمن في مركز السلامة
        $this->assertSame(4, $med[2]->role_card_no);             // الطبيب
        $this->assertSame([60, 180], [$med[2]->window_from_sec, $med[2]->window_to_sec]);

        $hz01 = $this->plan('HZ-01')->steps->where('path_key', 'fire')->values();
        $this->assertSame([0, 259200], [$hz01[11]->window_from_sec, $hz01[11]->window_to_sec]); // ٠–٧٢ ساعة
        $this->assertSame([3, 5], $hz01[1]->role_cards);                                          // رئيس الأمن عبر المراقبين

        // الكشف والبلاغ: ①②③ ثم نداء المركز (٢١)
        $det = $this->plan('HZ-06')->steps->where('path_key', 'detection')->values();
        $this->assertSame(['①', '②', '③', '←'], $det->pluck('label')->all());
        $this->assertSame(21, $det[3]->role_card_no);
        $this->assertSame([8, 9, 10, 11], $det[0]->role_cards);
    }

    public function test_every_live_step_has_a_role_card_except_the_technical_committee(): void
    {
        app(ResponsePlanSync::class)->sync($this->root);
        $live = ResponsePlanStep::whereIn('path_key', ResponsePlan::LIVE_PATHS)->get();
        $this->assertGreaterThan(100, $live->count());
        $noCard = $live->whereNull('role_card_no');
        // الاستثناء الوحيد المرصود في الوثائق: «اللجنة الفنية» (خطوة استعادة التشغيل) ليست من البطاقات الـ٢١ — قرار للمستخدم
        foreach ($noCard as $s) $this->assertStringContainsString('اللجنة الفنية', $s->who_text, $s->plan->place->code.' '.$s->title);
        $this->assertSame(7, $noCard->count());
        $this->assertSame(7, ResponsePlan::sum('no_card_count'));
        $this->assertSame(0, $this->plan('HZ-06')->no_card_count);
    }

    public function test_role_cards_registry_is_the_21_of_source_md(): void
    {
        $this->assertCount(21, RoleCards::CARDS);
        $cats = collect(RoleCards::CARDS)->groupBy('category')->map->count()->all();
        $this->assertSame(['leadership' => 5, 'support' => 9, 'response-team' => 5, 'occupants' => 2], $cats);
        foreach (RoleCards::CARDS as $no => $c) $this->assertFileExists($this->root.RoleCards::url($no), "بطاقة $no");
        $this->assertSame([21], RoleCards::match('مناوب الأمن في مركز السلامة'));
        $this->assertSame([3, 5], RoleCards::match('رئيس الأمن يوجّه فريق الأمن'));
        $this->assertSame([14, 15, 16, 17, 18, 19, 8], RoleCards::match('الفريق الفني المناوب + المنسق'));
        $this->assertSame([], RoleCards::match('اللجنة الفنية + الدفاع المدني'));
        $this->assertSame('دور: مناوب مركز السلامة', RoleCards::systemLabel(21));
        $this->assertSame('عضو الفريق الأولي بالمكان: المنسق', RoleCards::systemLabel(8));
    }

    public function test_modified_copy_is_reread_without_writing_back_and_unchanged_docs_are_skipped(): void
    {
        $sync = app(ResponsePlanSync::class);
        $sync->sync($this->root);
        $before = $this->plan('HZ-06');
        $originalHash = sha1_file($this->root.'/HZ-06-offices/response-plan.html');

        // لا تغيير ← لا إعادة قراءة
        $r = $sync->sync($this->root);
        $this->assertSame('unchanged', $r['HZ-06']['status']);

        // نسخة معدّلة في مجلد مؤقت (الأصل لا يُمس)
        $tmp = storage_path('framework/testing/plans-'.uniqid());
        File::copyDirectory($this->root.'/HZ-06-offices', $tmp.'/HZ-06-offices');
        $file = $tmp.'/HZ-06-offices/response-plan.html';
        $html = file_get_contents($file);
        $this->assertStringContainsString('التحكم بالأنظمة الحرجة', $html);
        file_put_contents($file, str_replace('التحكم بالأنظمة الحرجة', 'التحكم بالأنظمة الحرجة — تعديل تجريبي', $html));

        $r = $sync->sync($tmp);
        $this->assertSame('synced', $r['HZ-06']['status']);
        $this->assertSame('missing', $r['HZ-01']['status']); // المجلد المؤقت فيه مكان واحد
        $after = $this->plan('HZ-06');
        $this->assertNotSame($before->fingerprint, $after->fingerprint);
        $this->assertSame(12, $after->steps_count);
        $this->assertSame('التحكم بالأنظمة الحرجة — تعديل تجريبي', $after->steps->where('path_key', 'fire')->values()[2]->title);
        $this->assertSame($originalHash, sha1_file($this->root.'/HZ-06-offices/response-plan.html'), 'لا كتابة عكسية في الأصل');
        $this->assertSame(8, ResponsePlan::count(), 'الخطط الأخرى تبقى');

        // العودة إلى الأصل تعيد القراءة (البصمة اختلفت)
        $r = $sync->sync($this->root);
        $this->assertSame('synced', $r['HZ-06']['status']);
        $this->assertSame('التحكم بالأنظمة الحرجة', $this->plan('HZ-06')->steps->where('path_key', 'fire')->values()[2]->title);
        File::deleteDirectory($tmp);
    }

    public function test_screens_and_permissions(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $fani = $this->user('fani', 'field_worker', 'HZ-06');
        $employee = $this->user('emp', 'employee');

        // الفتح يزامن آلياً (الوثيقة هي الحقيقة)
        $r = $this->actingAs($salama)->get('/app/emergency/plans');
        $r->assertOk()->assertSee('HZ-06')->assertSee('المكاتب الإدارية')->assertSee('مزامنة من الوثائق')->assertSee('بطاقات الأدوار الـ٢١')->assertSee('مناوب مركز السلامة');
        $this->assertSame(8, ResponsePlan::count());

        $r = $this->actingAs($salama)->get('/app/emergency/plans/HZ-06');
        $r->assertOk()->assertSee('التحكم بالأنظمة الحرجة')->assertSee('مدير المرافق والصيانة')->assertSee('الثانية الأولى')->assertSee('يدخل القائمة الحية عند التفعيل');

        $this->actingAs($fani)->get('/app/emergency/plans')->assertOk();          // الاستجابة الميدانية تطّلع
        $this->actingAs($fani)->get('/app/emergency/plans/HZ-01')->assertOk();
        $this->actingAs($employee)->get('/app/emergency/plans')->assertForbidden();
        $this->actingAs($salama)->get('/app/emergency/plans/HZ-99')->assertNotFound();

        $this->actingAs($fani)->post('/app/emergency/plans/sync')->assertForbidden();
        $this->actingAs($salama)->from('/app/emergency/plans')->post('/app/emergency/plans/sync')
            ->assertRedirect('/app/emergency/plans')->assertSessionHas('success');
        $this->assertStringContainsString('8 خطة', session('success'));

        auth()->logout();
        $this->get('/app/emergency/plans')->assertRedirect('/login');
    }
}
