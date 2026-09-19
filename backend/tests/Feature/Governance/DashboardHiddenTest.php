<?php

namespace Tests\Feature\Governance;

use App\Console\Commands\IpaSyncSite;
use App\Core\Inbox\InboxService;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٩-٧ (قرار ٤٨): إخفاء «العمل اليومي» — الملف يبقى في المستودع كما هو، والموقع لا يعرضه:
 * الرابط القديم يُحوَّل إلى مقابله في الخلفية، ولا زر ولا بند يشير إليه، والمتصفح لا يكتب ملف الفرق ولا الهيكل مباشرة.
 */
class DashboardHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $fani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker');
    }

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    public function test_old_dashboard_links_land_on_their_backend_place(): void
    {
        $this->get('/dashboard.html')->assertRedirect('/login');

        $park = Place::where('code', 'HZ-01')->first();
        $h = $this->actingAs($this->fani)->get('/dashboard.html')->assertOk()->getContent();
        // الوسم (#place=…) لا يصل الخادم: الصفحة تحمل خريطة الأماكن وتحوّل في المتصفح
        $this->assertStringContainsString('"HZ-01":"\/app\/places\/'.$park->id.'\/file"', $h);
        $this->assertStringContainsString('/systems/', $h);
        $this->assertStringContainsString('href="/app"', $h);           // بلا جافاسكربت: رابط «ما ينتظرك»
        $this->assertStringNotContainsString('/app/org', $h);           // الفني لا يملك الهيكل
        $this->assertStringContainsString('"depts":"\/app\/org"', $this->actingAs($this->salama)->get('/dashboard.html')->getContent());

        // النسخ إلى public لا يشمل اللوحة
        $this->assertContains('dashboard.html', IpaSyncSite::HIDDEN);
    }

    public function test_nothing_in_the_backend_points_to_the_dashboard(): void
    {
        $h = $this->actingAs($this->salama)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('dashboard.html', $h);
        $this->assertStringNotContainsString('العمل اليومي', $h);

        $offices = Place::where('code', 'HZ-06')->first();
        $this->actingAs($this->salama)->get('/app/search?q=hz-06')->assertRedirect("/app/places/{$offices->id}/file");
        $this->actingAs($this->salama)->get('/app/search?q=المكاتب')->assertOk()->assertDontSee('dashboard.html', false)->assertSee("/app/places/{$offices->id}/file", false);

        foreach (['app', 'resources/views'] as $dir) {
            foreach (\Illuminate\Support\Facades\File::allFiles(base_path($dir)) as $f) {
                if (str_contains($f->getRelativePathname(), 'Routes')) continue; // مسار التحويل نفسه
                $this->assertDoesNotMatchRegularExpression('~/dashboard\.html[#\'"]~', $f->getContents(), $f->getRelativePathname().' ما زال يشير إلى اللوحة');
            }
        }
        // بلاغ الفحص في «ما ينتظرك»: التفاصيل ملف المكان لا اللوحة
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode(['defs' => [], 'rounds' => new \stdClass, 'reports' => [
            ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'عطل', 'due' => '٢٤ ساعة', 'when' => now()->format('Y/m/d').' — '.now()->format('H:i'), 'sent' => '', 'path' => 'إداري', 'levels' => []]]], JSON_UNESCAPED_UNICODE)]);
        $this->fani->profile->update(['place_id' => Place::idByCode('HZ-01')]); // ٢٠-٥: الفني يرى ما يغطيه (مكانه)
        $t = app(InboxService::class)->forUser($this->fani)->first(fn ($t) => str_starts_with($t->key, 'inspection:') || $t->module === 'بلاغات الفحص');
        $this->assertNotNull($t);
        $this->assertStringNotContainsString('dashboard', (string) $t->detailsUrl);
    }

    public function test_browser_cannot_write_teams_or_structure_documents_directly(): void
    {
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 3, 'data' => '{"HZ-01":{"plans":{},"units":{}}}']);
        foreach ([$this->fani, $this->salama] as $u) {
            $this->actingAs($u)->putJson('/api/store/ipa-place', ['data' => '{"HZ-01":{"units":{"_":{"appr":{"date":"2026-01-01"}}}}}', 'version' => 3])->assertStatus(422);
            $this->actingAs($u)->putJson('/api/store/ipa-depts', ['data' => '[]', 'version' => 0])->assertStatus(422);
            $this->actingAs($u)->deleteJson('/api/store/ipa-place')->assertStatus(422);
        }
        $this->assertSame(3, (int) InstituteDocument::where('key', 'ipa-place')->value('version'));
        // القراءة باقية (النماذج وشاشات الخلفية تقرأ)، وكتابة وثائق النماذج باقية
        $this->actingAs($this->fani)->getJson('/api/store/ipa-place')->assertOk();
        $this->actingAs($this->fani)->putJson('/api/store/ipa-park-form-v10', ['data' => '{"reports":[]}', 'version' => 0])->assertOk();
    }

    public function test_institute_files_return_to_the_inbox_not_the_dashboard(): void
    {
        $root = dirname(base_path());
        $forms = array_merge(glob($root.'/HZ-0*/inspection-form.html'), [$root.'/HZ-00-safety-center/fire-inspection.html']);
        $this->assertCount(10, $forms);
        foreach ($forms as $f) {
            $s = file_get_contents($f);
            $this->assertStringNotContainsString("a.href='../dashboard.html'", $s, basename(dirname($f)).': زر الرجوع ما زال إلى اللوحة');
            $this->assertStringContainsString("a.href='/app';a.textContent='← ما ينتظرك';", $s, basename(dirname($f)));
        }
        $i = file_get_contents($root.'/index.html');
        $this->assertStringNotContainsString("href='dashboard.html'", $i);
        $this->assertStringContainsString("enterBtn').textContent='فتح المنظومة ←';document.getElementById('enterBtn').href='/app';", $i);
        // اللوحة نفسها لم تُمس: باقية في المستودع
        $this->assertFileExists($root.'/dashboard.html');
    }
}
