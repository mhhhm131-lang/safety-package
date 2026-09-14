<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ١٤ (قرار ٤٢، التصميم ١٤-٢ المعتمد ٢٠٢٦-٠٩-١٤): إشعارات الفحص وسجل السلامة في الخادم.
 * المستلمون: مسؤول السلامة، مناوب مركز السلامة، مدير المرافق والصيانة — مرة واحدة لكل حدث.
 */
class InspectionWatchTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ipa-elec-form-v10';

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => $username, 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    /** الأدوار كلها؛ يعيد الفني */
    private function people(): User
    {
        $this->user('salama', 'system_admin');
        $this->user('munawib', 'system_staff');
        $this->user('marafiq', 'facilities_manager');
        $this->user('muwazzaf', 'employee');
        return $this->user('fani', 'field_worker');
    }

    /** وثيقة نموذج الكهرباء بالصيغة التي يكتبها النموذج (snapshot()) */
    private function doc(array $o = []): string
    {
        $fieldIds = ['sysName', 'dtRound', 'tStart', 'tEnd', 'insN', 'freq', 'mobSummary', 'mobNext'];
        $f = array_merge(['sysName' => 'لوحات التوزيع', 'dtRound' => '', 'tStart' => '', 'tEnd' => '', 'insN' => 'الفني المنفّذ', 'freq' => 'شهري', 'mobSummary' => '', 'mobNext' => ''], $o['fields'] ?? []);
        $d = [
            'v' => 41, 'sys' => 'e01', 'seq' => count($o['reports'] ?? []),
            'marks' => $o['marks'] ?? (object) [], 'vals' => $o['vals'] ?? (object) [],
            'rounds' => $o['rounds'] ?? (object) [],
            'reports' => $o['reports'] ?? [],
            'defs' => $o['defs'] ?? ['e01' => ['items' => [['اللوحة مغلقة', 'SBC']], 'reads' => ['حرارة القواطع'], 'name' => 'لوحات التوزيع الرئيسية والفرعية', 'code' => '٠١',
                'sched' => [['شهري', 'فحص بصري وحراري', 'الفني المختص']]]],
            'fields' => array_map(fn ($id) => $f[$id], $fieldIds), 'fieldIds' => $fieldIds,
        ];
        if (isset($o['roundDone'])) $d['roundDone'] = $o['roundDone'];
        return json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    private function report(string $ld = 'up', string $cycleAt = '', string $id = 'ب — ٠١'): array
    {
        return ['id' => $id, 'row' => 'e01-r-0', 'item' => 'أعلى درجة حرارة على القواطع', 'sev' => 'أ · حرج', 'due' => 'فوري',
            'desc' => 'حرارة مرتفعة', 'need' => 'فني كهرباء', 'when' => '٢٠٢٦/٠٩/١٤ — ١٧:٣٦', 'sent' => '٢٠٢٦/٠٩/١٤ — ١٧:٣٦', 'cycleAt' => $cycleAt,
            'levels' => ['1' => ['by' => 'الفني المنفّذ', 'date' => '٢٠٢٦/٠٩/١٤ — ١٧:٣٦', 'up' => $ld === 'up', 'ld' => $ld, 'ack' => $ld === 'close', 'note' => '']]];
    }

    private function saveDoc(User $u, string $data): void
    {
        $v = (int) DB::table('institute_documents')->where('key', self::KEY)->value('version');
        $this->actingAs($u)->putJson('/api/store/'.self::KEY, ['data' => $data, 'version' => $v])->assertOk();
    }

    private function notes(string $type): \Illuminate\Support\Collection
    {
        return AppNotification::where('type', $type)->get();
    }

    private function recipients(string $type): array
    {
        return $this->notes($type)->map(fn ($n) => User::find($n->user_id)->username)->sort()->values()->all();
    }

    public function test_sent_escalated_report_notifies_the_three_roles_once(): void
    {
        $fani = $this->people();
        $this->saveDoc($fani, $this->doc(['reports' => [$this->report('up')]]));

        $this->assertSame(['marafiq', 'munawib', 'salama'], $this->recipients('inspection_report'));
        $n = $this->notes('inspection_report')->first();
        $this->assertStringContainsString('ب — ٠١', $n->title);
        $this->assertStringContainsString('غرف الكهرباء', $n->title);
        $this->assertStringContainsString('صُعِّد', $n->message);
        $this->assertSame('/HZ-02-electrical/inspection-form.html#open=e01-r-0', $n->url);

        // حفظ آخر للوثيقة نفسها (تأشير بند) لا يكرر الإشعار
        $this->saveDoc($fani, $this->doc(['reports' => [$this->report('up')], 'marks' => ['e01-i-0' => 'ok']]));
        $this->assertCount(3, $this->notes('inspection_report'));
    }

    public function test_resolved_on_site_report_says_so(): void
    {
        $fani = $this->people();
        $this->saveDoc($fani, $this->doc(['reports' => [$this->report('close')]]));
        $this->assertCount(3, $this->notes('inspection_report'));
        $this->assertStringContainsString('عولج موقعياً', $this->notes('inspection_report')->first()->message);
    }

    public function test_report_not_yet_sent_does_not_notify_and_a_new_cycle_does(): void
    {
        $fani = $this->people();
        $draft = $this->report('up'); unset($draft['levels']['1']);
        $this->saveDoc($fani, $this->doc(['reports' => [$draft]]));
        $this->assertCount(0, $this->notes('inspection_report'));

        $this->saveDoc($fani, $this->doc(['reports' => [$this->report('up')]]));
        $this->assertCount(3, $this->notes('inspection_report'));
        // إعادة الفتح = دورة جديدة بمهلة جديدة → إشعار جديد
        $this->saveDoc($fani, $this->doc(['reports' => [$this->report('close', '٢٠٢٦/٠٩/١٥ — ٠٩:٠٠')]]));
        $this->assertCount(6, $this->notes('inspection_report'));
    }

    public function test_round_is_kept_whole_after_a_new_round_and_notified_once(): void
    {
        $fani = $this->people();
        $round = ['fields' => ['dtRound' => '2026-09-14', 'tStart' => '10:00', 'tEnd' => '10:40', 'mobSummary' => 'اللوحة ساخنة'],
            'marks' => ['e01-r-0' => 'no', 'e01-i-0' => 'ok'], 'vals' => ['e01-r-0|act' => '78'],
            'rounds' => ['e01' => [['d' => '2026-09-14', 's' => '10:00', 't' => '10:40', 'q' => 'فني مختص', 'n' => 'الفني المنفّذ', 'f' => 'شهري', 'ok' => 1, 'no' => 1, 'na' => 0, 'tot' => 2]]],
            'reports' => [$this->report('up')]];
        $this->saveDoc($fani, $this->doc($round));
        $this->assertSame(1, DB::table('inspection_rounds')->count());
        $this->assertCount(0, $this->notes('inspection_round'));

        // «جولة جديدة» في النموذج: تُمسح العلامات والقيم وتاريخ الجولة، ويبقى سطرها في rounds
        $this->saveDoc($fani, $this->doc(['rounds' => $round['rounds'], 'reports' => [$this->report('up')]]));
        $r = DB::table('inspection_rounds')->first();
        $this->assertNotNull($r->closed_at);
        $snap = json_decode($r->snapshot, true);
        $this->assertSame('no', $snap['marks']['e01-r-0']);
        $this->assertSame('78', $snap['vals']['e01-r-0|act']);
        $this->assertSame('اللوحة ساخنة', $snap['fields']['mobSummary']);
        $this->assertSame(['ب — ٠١'], $snap['reports']);
        $this->assertSame(['marafiq', 'munawib', 'salama'], $this->recipients('inspection_round'));
        $this->assertStringContainsString('غرف الكهرباء', $this->notes('inspection_round')->first()->title);

        $this->saveDoc($fani, $this->doc(['rounds' => $round['rounds'], 'reports' => [$this->report('up')], 'marks' => ['e01-i-0' => 'na']]));
        $this->assertCount(3, $this->notes('inspection_round'));
    }

    public function test_finish_round_button_notifies_at_once_and_not_again_when_the_next_round_starts(): void
    {
        $fani = $this->people();
        $rounds = ['e01' => [['d' => '2026-09-14', 's' => '10:00', 't' => '10:40', 'q' => 'فني مختص', 'n' => 'الفني المنفّذ', 'f' => 'شهري', 'ok' => 2, 'no' => 0, 'na' => 0, 'tot' => 2]]];
        $this->saveDoc($fani, $this->doc(['fields' => ['dtRound' => '2026-09-14', 'tStart' => '10:00'], 'marks' => ['e01-i-0' => 'ok'], 'rounds' => $rounds,
            'roundDone' => ['d' => '2026-09-14', 's' => '10:00']]));
        $this->assertCount(3, $this->notes('inspection_round'));

        $this->saveDoc($fani, $this->doc(['rounds' => $rounds]));
        $this->assertCount(3, $this->notes('inspection_round'));
    }

    public function test_overdue_monthly_check_notifies_once_immediately_and_stops_after_a_new_round(): void
    {
        $fani = $this->people();
        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00', config('app.timezone')));
        $old = ['e01' => [['d' => '2026-08-15', 's' => '09:00', 't' => '09:30', 'q' => 'فني مختص', 'n' => 'الفني', 'f' => 'شهري', 'ok' => 2, 'no' => 0, 'na' => 0, 'tot' => 2]]];

        // مستحق ٢٠٢٦-٠٩-١٤ (٢٠٢٦-٠٨-١٥ + ٣٠): لم يفت بعد
        $this->saveDoc($fani, $this->doc(['rounds' => $old]));
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->assertCount(0, $this->notes('inspection_overdue'));

        // يوم بعده: فات موعده → فوراً ومرة واحدة
        Carbon::setTestNow(Carbon::parse('2026-09-15 00:30', config('app.timezone')));
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->assertSame(['marafiq', 'munawib', 'salama'], $this->recipients('inspection_overdue'));
        $n = $this->notes('inspection_overdue')->first();
        $this->assertStringContainsString('شهري', $n->title);
        $this->assertStringContainsString('غرف الكهرباء', $n->title);
        $this->assertSame('/HZ-02-electrical/inspection-form.html#sys=e01', $n->url);

        // جولة شهرية جديدة → لا إشعار آخر
        $new = ['e01' => array_merge($old['e01'], [['d' => '2026-09-15', 's' => '08:00', 't' => '08:30', 'q' => 'فني مختص', 'n' => 'الفني', 'f' => 'شهري', 'ok' => 2, 'no' => 0, 'na' => 0, 'tot' => 2]])];
        $this->saveDoc($fani, $this->doc(['rounds' => $new]));
        Carbon::setTestNow(Carbon::parse('2026-09-16 00:30', config('app.timezone')));
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->assertCount(3, $this->notes('inspection_overdue'));
        Carbon::setTestNow();
    }

    public function test_never_inspected_systems_get_one_daily_summary_and_event_driven_rows_are_ignored(): void
    {
        $fani = $this->people();
        $defs = [
            'e01' => ['items' => [['أ', 'م']], 'reads' => [], 'name' => 'لوحات', 'code' => '٠١', 'sched' => [['أسبوعي', 'جولة', 'مسؤول السلامة'], ['شهري', 'فحص', 'الفني المختص']]],
            'e02' => ['items' => [['ب', 'م']], 'reads' => [], 'name' => 'مولد', 'code' => '٠٢', 'sched' => [['قبل كل فعالية', 'تجربة', 'الفني المختص']]],
        ];
        $this->saveDoc($fani, $this->doc(['defs' => $defs]));
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00', config('app.timezone')));
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->assertSame(['marafiq', 'munawib', 'salama'], $this->recipients('inspection_never'));
        $this->assertCount(0, $this->notes('inspection_overdue'));
        // سطران بدورية ثابتة لم يُنفَّذا؛ «قبل كل فعالية» لا يُعدّ
        $this->assertStringContainsString('2', strtr($this->notes('inspection_never')->first()->title, ['٢' => '2']));

        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00', config('app.timezone')));
        $this->artisan('inspections:check-overdue')->assertSuccessful();
        $this->assertCount(6, $this->notes('inspection_never'));
        Carbon::setTestNow();
    }

    public function test_previous_rounds_are_readable_for_daily_work_roles_only(): void
    {
        $fani = $this->people();
        $rounds = ['e01' => [['d' => '2026-09-14', 's' => '10:00', 't' => '10:40', 'q' => 'فني مختص', 'n' => 'الفني المنفّذ', 'f' => 'شهري', 'ok' => 1, 'no' => 0, 'na' => 0, 'tot' => 2]]];
        $this->saveDoc($fani, $this->doc(['fields' => ['dtRound' => '2026-09-14', 'tStart' => '10:00'], 'marks' => ['e01-i-0' => 'ok'], 'rounds' => $rounds]));

        $list = $this->actingAs($fani)->getJson('/api/inspection-rounds?key='.self::KEY)->assertOk()->json('rounds');
        $this->assertCount(1, $list);
        $this->assertSame('2026-09-14', $list[0]['date']);
        $this->assertSame('10:00', $list[0]['start']);
        $one = $this->actingAs($fani)->getJson('/api/inspection-rounds/'.$list[0]['id'])->assertOk();
        $this->assertSame('ok', $one->json('snapshot.marks.e01-i-0'));

        $emp = User::where('username', 'muwazzaf')->first();
        $this->actingAs($emp)->getJson('/api/inspection-rounds?key='.self::KEY)->assertStatus(403);
    }
}
