<?php

namespace Tests\Feature\Closeout;

use App\Core\Services\BackupService;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٨-٣ — النسخة الاحتياطية.
 *
 * التفريغ بـPHP لا `pg_dump`: حاوية Render بلا أدوات Postgres وبلا سطر أوامر.
 * والنسخة التي تبقى هي المنزَّلة، لأن الخطة المجانية بلا قرص دائم.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $fani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani   = $this->user('fani', 'field_worker');
    }

    private function user(string $username, string $role): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}",
            'password' => '123456', 'email' => "{$username}@example.test",
        ]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);

        return $u;
    }

    private function dumpText(): string
    {
        $sql = '';
        app(BackupService::class)->dump(function (string $line) use (&$sql) {
            $sql .= $line;
        });

        return $sql;
    }

    public function test_dump_contains_the_real_rows(): void
    {
        $sql = $this->dumpText();

        $this->assertStringContainsString('INSERT INTO "places"', $sql);
        $this->assertStringContainsString('HZ-06', $sql);
        $this->assertStringContainsString('INSERT INTO "users"', $sql);
        $this->assertStringContainsString('salama', $sql);
    }

    public function test_dump_skips_session_and_cache_tables(): void
    {
        $tables = app(BackupService::class)->tables();

        foreach (['cache', 'sessions', 'jobs', 'failed_jobs'] as $skipped) {
            $this->assertNotContains($skipped, $tables, "الجدول {$skipped} مؤقت ولا يُنسخ");
        }
        $this->assertContains('places', $tables);
    }

    public function test_dump_escapes_quotes_so_the_file_stays_valid(): void
    {
        Place::where('code', 'HZ-06')->update(['name' => "مكاتب 'الإدارة' العامة"]);

        $sql = $this->dumpText();

        // الاقتباس المفرد يُضاعف داخل القيمة
        $this->assertStringContainsString("''الإدارة''", $sql);
    }

    public function test_dump_writes_null_not_empty_string(): void
    {
        $sql = $this->dumpText();
        $this->assertStringContainsString('NULL', $sql);
    }

    public function test_dump_reports_table_and_row_counts(): void
    {
        $stats = app(BackupService::class)->dump(fn () => null);

        $this->assertGreaterThan(0, $stats['tables']);
        $this->assertGreaterThanOrEqual(9, $stats['rows'], 'الأماكن التسعة على الأقل');
    }

    public function test_command_writes_a_file_and_keeps_only_the_requested_count(): void
    {
        $backup = app(BackupService::class);
        foreach ($backup->existing() as $old) {
            @unlink($old['path']);
        }

        for ($i = 0; $i < 3; $i++) {
            $backup->writeToDisk();
            // الاسم يحمل الثانية، فيلزم فارق لضمان ملفات مختلفة
            touch($backup->existing()[0]['path'], time() - $i);
            usleep(1100000);
        }

        $this->assertGreaterThanOrEqual(3, count($backup->existing()));

        $backup->prune(2);
        $this->assertCount(2, $backup->existing());

        foreach ($backup->existing() as $file) {
            @unlink($file['path']);
        }
    }

    public function test_command_runs(): void
    {
        $this->artisan('ipa:backup --keep=2')->assertSuccessful();

        foreach (app(BackupService::class)->existing() as $file) {
            @unlink($file['path']);
        }
    }

    // ════════════ التنزيل ════════════

    public function test_download_requires_settings_permission(): void
    {
        $this->actingAs($this->fani)->get(route('app.closeout.backup'))->assertForbidden();
    }

    public function test_download_streams_sql(): void
    {
        $response = $this->actingAs($this->salama)->get(route('app.closeout.backup'));

        $response->assertOk();
        $this->assertStringContainsString('sql', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('INSERT INTO "places"', $body);
        $this->assertStringContainsString('منظومة السلامة', $body);
    }

    public function test_closeout_screen_offers_the_download_before_deleting(): void
    {
        $this->actingAs($this->salama)->get(route('app.closeout.index'))
            ->assertOk()
            ->assertSee('نزّل نسخة الآن')
            ->assertSee(route('app.closeout.backup'), false);
    }
}
