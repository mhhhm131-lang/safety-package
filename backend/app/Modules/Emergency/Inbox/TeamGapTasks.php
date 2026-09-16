<?php

namespace App\Modules\Emergency\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٥-٥ (قرار ٤٣): «ينقصك فريق» — فريق أولي لكل ٢٥ موظفاً أو جزء منها في كل وحدة بمكانها.
 * يقرأ ملف المكان في اللوحة (ipa-place) كما يقرأ InspectionReportTasks وثائق النماذج، ولا يكتب شيئاً.
 * لمسؤول السلامة والمناوب ومدير الشؤون الإدارية والهندسية: كل الوحدات؛ ولمدير الوحدة: وحدته.
 * بلا عدد موظفين مسجَّل لا نقص محسوب (لا رقم مخترع).
 */
class TeamGapTasks implements TaskSource
{
    public const PER_TEAM = 25;

    private const WORDS = [1 => 'فريق واحد', 2 => 'فريقين', 3 => 'ثلاثة فرق', 4 => 'أربعة فرق', 5 => 'خمسة فرق'];

    public function tasksFor(User $user): Collection
    {
        $profile = UserProfile::where('user_id', $user->id)->first();
        if (!$profile || !$profile->is_active) return collect();
        $ui = PermissionRegistry::uiRole($profile->role);
        $all = in_array($ui, ['safety', 'adm'], true);
        $myUnit = $profile->organization_unit_id ? OrganizationUnit::find($profile->organization_unit_id)?->code : null;
        if (!$all && !($ui === 'dept' && $myUnit)) return collect();

        $raw = InstituteDocument::where('key', 'ipa-place')->value('data');
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) return collect();

        $places = Place::all()->keyBy('code');
        $units = OrganizationUnit::all()->keyBy('code');
        $out = collect();
        foreach ($data as $hz => $p) {
            if (!is_array($p) || !isset($places[$hz])) continue;
            foreach ((array) ($p['units'] ?? []) as $uid => $u) {
                if (!is_array($u)) continue;
                $uid = (string) $uid;
                $code = $uid !== '_' ? $uid : (string) ($u['dept'] ?? ($u['nom']['dept'] ?? ''));
                if (!$all && $code !== $myUnit) continue;
                $staff = (int) ($u['staff'] ?? 0);
                if ($staff <= 0) continue;
                $need = (int) ceil($staff / self::PER_TEAM);
                $ready = $this->readyTeams($u);
                if ($ready >= $need) continue;
                $label = $units[$code]->name ?? ($uid === '_' ? 'الإدارة المشغّلة للمكان' : $uid);
                $out->push(new Task(
                    key: 'team-gap:'.$hz.':'.$uid,
                    module: 'الفريق الأولي',
                    question: $label.' في '.$places[$hz]->name.': '.$this->ar($staff).' موظفاً تحتاج '.$this->word($need)
                        .' — المعتمد: '.($ready ? $this->word($ready) : 'لا فريق'),
                    primary: ['label' => 'افتح ملف المكان', 'url' => '/dashboard.html#place='.$hz],
                    place: $hz.' '.$places[$hz]->name,
                    detailsUrl: '/dashboard.html#place='.$hz,
                ));
            }
        }
        return $out;
    }

    /** الفريق المعتمد: اعتُمد أو أُحيل للموارد البشرية — كما تعدّه اللوحة (unitState appr/hr)؛ الأول في جذر الوحدة والباقي في more */
    private function readyTeams(array $u): int
    {
        $teams = array_merge([$u], array_values(array_filter((array) ($u['more'] ?? []), 'is_array')));
        $ready = 0;
        foreach ($teams as $t) {
            if (!empty($t['appr']['date']) || !empty($t['hr']['date'])) $ready++;
        }
        return $ready;
    }

    private function word(int $n): string
    {
        return self::WORDS[$n] ?? $this->ar($n).' فرق';
    }

    private function ar(int|string $n): string
    {
        return strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
