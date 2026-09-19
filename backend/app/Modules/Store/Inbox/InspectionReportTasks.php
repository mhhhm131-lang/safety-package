<?php

namespace App\Modules\Store\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Store\Models\InstituteDocument;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * المرحلة ١١-٣ (قرار ٣٤): بلاغات الفحص الفني (نماذج المعهد العشرة) بصيغة مهام — قراءة فقط.
 * الخلفية تقرأ وثائق النماذج من جدولها (institute_documents، كما TeamSync يقرأ ipa-place) وتنقل منطق
 * dashboard.html:461-488 (isClosed / overdue / lastLvl / mine) إلى PHP حرفياً. لا يُمس أي ملف معهدي.
 * الزر يفتح النموذج على السطر نفسه (`{file}#open={row}` كما link(r) في اللوحة)؛ القرار يبقى داخل النموذج.
 */
class InspectionReportTasks implements TaskSource
{
    /** الأماكن والنماذج كما في dashboard.html:410-421 */
    public const FORMS = [
        ['hz' => 'HZ-01', 'name' => 'القبو ومواقف السيارات', 'key' => 'ipa-park-form-v10',   'file' => 'HZ-01-basement/inspection-form.html'],
        ['hz' => 'HZ-02', 'name' => 'غرف الكهرباء',          'key' => 'ipa-elec-form-v10',   'file' => 'HZ-02-electrical/inspection-form.html'],
        ['hz' => 'HZ-03', 'name' => 'غرف التكييف',           'key' => 'ipa-hvac-form-v10',   'file' => 'HZ-03-hvac/inspection-form.html'],
        ['hz' => 'HZ-04', 'name' => 'مركز البيانات',         'key' => 'ipa-dc-form-v10',     'file' => 'HZ-04-datacenter/inspection-form.html'],
        ['hz' => 'HZ-00', 'name' => 'مركز السلامة',          'key' => 'ipa-center-form-v10', 'file' => 'HZ-00-safety-center/inspection-form.html'],
        ['hz' => 'HZ-00', 'name' => 'مركز السلامة',          'key' => 'ipa-fire-form-v41',   'file' => 'HZ-00-safety-center/fire-inspection.html'],
        ['hz' => 'HZ-05', 'name' => 'المطاعم',               'key' => 'ipa-food-form-v10',   'file' => 'HZ-05-restaurants/inspection-form.html'],
        ['hz' => 'HZ-06', 'name' => 'المكاتب الإدارية',      'key' => 'ipa-office-form-v10', 'file' => 'HZ-06-offices/inspection-form.html'],
        ['hz' => 'HZ-07', 'name' => 'القاعات التدريبية',     'key' => 'ipa-halls-form-v10',  'file' => 'HZ-07-halls/inspection-form.html'],
        ['hz' => 'HZ-08', 'name' => 'المخازن',               'key' => 'ipa-store-form-v10',  'file' => 'HZ-08-storage/inspection-form.html'],
    ];

    /** المهل بالساعات كما في النماذج (dashboard.html:461) — قيم النماذج لا قيم مخترعة */
    private const DUE_H = ['فوري' => 1, '٢٤ ساعة' => 24, '٧٢ ساعة' => 72];
    private const LNAME = [1 => 'الفني المنفّذ', 2 => 'مدير المرافق والصيانة', 3 => 'مدير الشؤون الإدارية والهندسية', 4 => 'الإدارة العليا'];

    public function tasksFor(User $user): Collection
    {
        $ui = PermissionRegistry::uiRole($user->role());
        if (!in_array($ui, ['tech', 'fm', 'adm', 'exec', 'safety'], true)) return collect();

        $docs = InstituteDocument::whereIn('key', array_column(self::FORMS, 'key'))->get()->keyBy('key');
        // ٢٠-٥ (قرار ٥١): الفني يرى بلاغات الأماكن التي يغطيها وبتخصصه (صف البلاغ يبدأ بمفتاح النظام)
        $role = $user->role();
        $scope = $ui === 'tech' ? \App\Modules\Governance\Services\ScopeService::forUser($user) : null;
        $out = collect();
        foreach (self::FORMS as $f) {
            $doc = $docs->get($f['key']);
            if (!$doc) continue;
            if ($scope && !$scope->contains($f['hz'])) continue;
            $data = json_decode($doc->data, true);
            foreach ((array) ($data['reports'] ?? []) as $r) {
                if (!is_array($r) || !$this->mine($r, $ui)) continue;
                if ($scope && !\App\Modules\Store\Services\SystemSpecialty::fits($role, (string) ($r['row'] ?? ''))) continue;
                $holder = $this->holder($r);
                $over = $this->overdueHours($r);
                $started = $this->parseStamp($r['cycleAt'] ?? '') ?? $this->parseStamp($r['when'] ?? '') ?? $this->parseStamp($r['sent'] ?? '');
                $h = self::DUE_H[$r['due'] ?? ''] ?? null;
                $row = (string) ($r['row'] ?? '');
                $out->push(new Task(
                    key: 'inspection:'.$f['key'].':'.$row,
                    module: 'بلاغات الفحص',
                    question: 'بلاغ فحص '.($r['id'] ?? $row).' في '.$f['name'].(!empty($r['unit']) ? ' · '.mb_substr((string) $r['unit'], 0, 40) : '').': '.mb_substr((string) ($r['item'] ?? ''), 0, 60) // ١٨-٣ (ج): الوحدة إن حملتها الصف
                        .' — '.($ui === 'tech' ? 'قرارك: عولج أم تعذّر' : 'ينتظر قرارك (المستوى '.(self::LNAME[$holder] ?? $holder).')'),
                    primary: ['label' => 'افتحه', 'url' => '/'.$f['file'].'#open='.rawurlencode($row)],
                    dueAt: ($started && $h) ? Carbon::instance($started)->addHours($h) : null,
                    isOverdue: $over !== null && $over > 0,
                    place: $f['hz'].' '.$f['name'],
                    detailsUrl: ($pid = \App\Modules\Governance\Models\Place::idByCode($f['hz'])) ? '/app/places/'.$pid.'/file' : null, // ١٩-٧
                    createdAt: $started ? Carbon::instance($started) : null,
                ));
            }
        }
        return $out;
    }

    // ── منطق اللوحة حرفياً (dashboard.html:462-488) — المرحلة ١٩-١: المصدر الواحد InspectionDocReader ──

    private function isClosed(array $r): bool { return \App\Modules\Store\Services\InspectionDocReader::isClosed($r); }

    private function lastLvl(array $r): int { return \App\Modules\Store\Services\InspectionDocReader::lastLvl($r); }

    private function holder(array $r): int { return \App\Modules\Store\Services\InspectionDocReader::holder($r); }

    private function mine(array $r, string $ui): bool
    {
        $last = $this->lastLvl($r); $closed = $this->isClosed($r); $L = (array) ($r['levels'] ?? []);
        $up = fn (int $n) => !empty($L[$n]['up']);
        return match ($ui) {
            'tech' => !$closed && ($last === 0 || !empty($r['backFrom'])),
            'fm' => !$closed && $last === 1 && $up(1),
            'adm' => !$closed && $last === 2 && $up(2),
            'exec' => !$closed && (($last === 3 && $up(3)) || (!empty($r['path']) && $r['path'] !== 'إداري' && $last > 0 && $last < 4 && $up($last))),
            'safety' => !$closed,
            default => false,
        };
    }

    /** الساعات فوق المهلة (موجب = متأخر)؛ null بلا مهلة أو بلا طابع زمني */
    private function overdueHours(array $r): ?float { return \App\Modules\Store\Services\InspectionDocReader::overdueHours($r); }

    private function parseStamp(?string $s): ?\DateTimeImmutable { return \App\Modules\Store\Services\InspectionDocReader::parseStamp($s); }
}
