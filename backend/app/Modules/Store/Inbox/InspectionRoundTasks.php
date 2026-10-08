<?php

namespace App\Modules\Store\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Store\Models\InstituteDocument;
use App\Modules\Store\Services\InspectionDocReader as R;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٩-٣ (قرار ٤٨): «مهامي» من العمل اليومي (dashboard.html:741-781) — الجولات المستحقة على دور المستخدم من الجدول الدوري لكل نظام.
 * في «ما ينتظرك» بطاقة واحدة لكل نموذج (حتى لا تغرق القائمة): «٥ جولات مستحقة في القبو، ٢ متأخرة» وزرها يفتح النموذج؛ التفصيل في ملف المكان.
 * قراءة فقط من وثائق النماذج؛ لا يُمس أي ملف معهدي.
 * ٢٨-٣ (قرار ٧٨): لكل مبنى من مباني الحساب؛ البطاقة تسمّي المبنى حين يتعدد، والرابط يحمله.
 */
class InspectionRoundTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        $ui = PermissionRegistry::uiRole($user->role());
        if (!$ui || !isset(R::WHO_ROLE[$ui])) return collect();

        // ٢٠-٥ (قرار ٥١): الفني يرى جولات الأماكن التي يغطيها وبتخصصه (الجدول المعتمد؛ النظام بلا تخصص لأي فني يغطي المكان)
        $role = $user->role();
        $scope = \App\Modules\Governance\Services\ScopeService::forUser($user);
        $buildings = BuildingContext::choices($user);
        $multi = $buildings->count() > 1;
        $myPlace = ($pid = UserProfile::where('user_id', $user->id)->value('place_id')) ? Place::find($pid)?->category : null;
        $out = collect();
        foreach ($buildings as $bld) {
            $placesByCat = Place::where('building_id', $bld->id)->get()->keyBy('category');
            $placeOf = fn (string $hz) => $placesByCat->get($hz);
            $due = R::dueRounds($ui, true, $bld->id);
            if ($ui === 'tech') $due = array_values(array_filter($due, fn ($t) => ($p = $placeOf($t['hz'])) && $scope->contains($p->code) && \App\Modules\Store\Services\SystemSpecialty::fits($role, $t['k'])));
            $byForm = []; $orphan = [];
            foreach ($due as $t) $byForm[$t['form']['key']][] = $t;
            // مدير المرافق: جولات الفني التي لا يغطيها فني بتخصصها تصله ليوزعها
            if ($ui === 'fm') {
                foreach (R::dueRounds('tech', true, $bld->id) as $t) {
                    $p = $placeOf($t['hz']);
                    if ($p && \App\Modules\Governance\Services\ScopeService::techniciansFor($p->code, $t['k'])->isEmpty()) $orphan[$t['form']['key']][] = $t;
                }
            }
            if (!$byForm && !$orphan) continue;
            $suffix = $multi ? ' · '.$bld->name : '';
            foreach ($byForm as $key => $list) {
                $f = $list[0]['form']; $n = count($list);
                $late = count(array_filter($list, fn ($t) => $t['left'] === null || $t['left'] < 0));
                $nexts = array_filter(array_column($list, 'next'));
                $p = $placeOf($f['hz']);
                $out->push(new Task(
                    key: 'rounds:'.($multi ? 'b'.$bld->id.':' : '').$key,
                    module: 'جولات الفحص',
                    question: self::countText($n).' في '.$f['name'].$suffix.self::lateText($n, $late).' — '.implode('، ', array_slice(array_unique(array_column($list, 'system')), 0, 3)).($n > 3 ? '…' : ''),
                    primary: ['label' => 'افتح النموذج', 'url' => InstituteDocument::fileUrl($f['file'], $bld->id)],
                    secondary: $p ? ['label' => 'ملف المكان', 'url' => route('app.places.units.file', $p)] : null,
                    dueAt: $nexts ? Carbon::parse(min($nexts)) : null,
                    isOverdue: $late > 0,
                    place: $f['hz'].' '.$f['name'].$suffix,
                    detailsUrl: $p ? route('app.places.units.file', $p) : null,
                ));
            }
            foreach ($orphan as $key => $list) {
                $f = $list[0]['form']; $n = count($list); $p = $placeOf($f['hz']);
                $out->push(new Task(
                    key: 'rounds-orphan:'.($multi ? 'b'.$bld->id.':' : '').$key,
                    module: 'جولات الفحص',
                    question: 'لا فني يغطي '.$f['name'].$suffix.': '.self::countText($n).' بلا من يستلمها — '.implode('، ', array_slice(array_unique(array_column($list, 'system')), 0, 3)).($n > 3 ? '…' : '').' — سجّل فنياً أو وسّع تغطية أحدهم',
                    primary: ['label' => 'فنيّي', 'url' => route('app.users.index', [], false)],
                    secondary: $p ? ['label' => 'ملف المكان', 'url' => route('app.places.units.file', $p)] : null,
                    isOverdue: count(array_filter($list, fn ($t) => $t['left'] === null || $t['left'] < 0)) > 0,
                    place: $f['hz'].' '.$f['name'].$suffix,
                    detailsUrl: $p ? route('app.places.units.file', $p) : null,
                ));
            }
        }
        // مكان المستخدم أولاً
        return $out->sortBy(fn (Task $t) => ($myPlace && str_starts_with((string) $t->place, $myPlace)) ? 0 : 1)->values();
    }

    private static function countText(int $n): string
    {
        return $n === 1 ? 'جولة مستحقة' : ($n === 2 ? 'جولتان مستحقتان' : ($n <= 10 ? $n.' جولات مستحقة' : $n.' جولة مستحقة'));
    }

    private static function lateText(int $n, int $late): string
    {
        if (!$late) return '';
        if ($late === $n) return $n === 1 ? ' (متأخرة أو لم تُنفَّذ)' : ($n === 2 ? ' (كلتاهما متأخرة أو لم تُنفَّذ)' : ' (كلها متأخرة أو لم تُنفَّذ)');
        return ' ('.$late.' منها متأخرة أو لم تُنفَّذ)';
    }
}
