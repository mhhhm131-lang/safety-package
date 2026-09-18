<?php

namespace App\Modules\Store\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Services\InspectionDocReader as R;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٩-٣ (قرار ٤٨): «مهامي» من العمل اليومي (dashboard.html:741-781) — الجولات المستحقة على دور المستخدم من الجدول الدوري لكل نظام.
 * في «ما ينتظرك» بطاقة واحدة لكل نموذج (حتى لا تغرق القائمة): «٥ جولات مستحقة في القبو، ٢ متأخرة» وزرها يفتح النموذج؛ التفصيل في ملف المكان.
 * قراءة فقط من وثائق النماذج؛ لا يُمس أي ملف معهدي.
 */
class InspectionRoundTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        $ui = PermissionRegistry::uiRole($user->role());
        if (!$ui || !isset(R::WHO_ROLE[$ui])) return collect();

        $byForm = [];
        foreach (R::dueRounds($ui) as $t) $byForm[$t['form']['key']][] = $t;
        if (!$byForm) return collect();

        $myPlace = ($pid = UserProfile::where('user_id', $user->id)->value('place_id')) ? Place::find($pid)?->code : null;
        $out = collect();
        foreach ($byForm as $key => $list) {
            $f = $list[0]['form']; $n = count($list);
            $late = count(array_filter($list, fn ($t) => $t['left'] === null || $t['left'] < 0));
            $nexts = array_filter(array_column($list, 'next'));
            $out->push(new Task(
                key: 'rounds:'.$key,
                module: 'جولات الفحص',
                question: self::countText($n).' في '.$f['name'].self::lateText($n, $late).' — '.implode('، ', array_slice(array_unique(array_column($list, 'system')), 0, 3)).($n > 3 ? '…' : ''),
                primary: ['label' => 'افتح النموذج', 'url' => '/'.$f['file']],
                secondary: ($p = Place::where('code', $f['hz'])->first()) ? ['label' => 'ملف المكان', 'url' => route('app.places.units.file', $p)] : null,
                dueAt: $nexts ? Carbon::parse(min($nexts)) : null,
                isOverdue: $late > 0,
                place: $f['hz'].' '.$f['name'],
                detailsUrl: $p ? route('app.places.units.file', $p) : null,
            ));
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
