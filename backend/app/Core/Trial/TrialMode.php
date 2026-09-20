<?php

namespace App\Core\Trial;

use App\Modules\Governance\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): «وضع التجربة» — النظام لم يُطلق، فيُشغَّل كاملاً ببيانات تجريبية ثم تُحذف.
 *
 * **الحدّ:** كل ما يُنشأ والوضع مشغَّل تجريبي — بالتعبئة أو بالجولة الآلية أو بيد المستخدم — ويُحذف عند الإنهاء؛
 * وما كان قبل التشغيل لا يُمس. لا وسم في الصفوف: عند التشغيل يُحفظ أعلى `id` في كل جدول (خط الأساس)،
 * وعند الإنهاء يُحذف ما فوقه. هذا يلتقط ما تكتبه النماذج وما يُدرج مباشرة (`DB::table`) معاً.
 * ملفات المعهد (`institute_documents`) والإعدادات تُنسخ قبل التشغيل وتُعاد بعده، لأن التجربة تعدّل صفوفاً قائمة فيها.
 *
 * **ما لا يُرجَع:** تعديل صف قديم في غير هذين الجدولين (اسم حساب، عدّاد خطر) — الحذف لما أُنشئ فقط.
 */
class TrialMode
{
    public const SETTING = 'trial.mode';

    /** جداول الإطار وحالة التجربة نفسها: لا خط أساس لها ولا حذف منها */
    private const SKIP = ['trial_state', 'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens', 'sqlite_sequence'];

    /** تُنسخ كاملة وتُعاد: التجربة تعدّل صفوفها القائمة */
    private const SNAPSHOT = ['institute_documents', 'settings'];

    public static function isOn(): bool
    {
        try {
            return (string) Setting::get(self::SETTING) === '1';
        } catch (\Throwable $e) {
            return false; // قبل الترحيل
        }
    }

    /** @return array{tables:int} */
    public function start(?int $userId = null): array
    {
        if (self::isOn()) throw new RuntimeException('وضع التجربة مشغَّل أصلاً — أنهِه أولاً.');
        if (DB::table('trial_state')->exists()) throw new RuntimeException('بقايا تجربة سابقة في trial_state — أنهِها أولاً (ipa:trial-stop).');

        $now = now();
        $rows = [];
        foreach ($this->tables() as $t) {
            if (in_array($t, self::SNAPSHOT, true)) continue;
            if (!$this->hasIntId($t)) continue;
            $rows[] = ['kind' => 'baseline', 'name' => $t, 'value' => (string) (int) DB::table($t)->max('id'), 'created_at' => $now];
        }
        foreach (self::SNAPSHOT as $t) {
            $rows[] = ['kind' => 'snapshot', 'name' => $t, 'value' => json_encode(DB::table($t)->get()->map(fn ($r) => (array) $r)->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now];
        }
        DB::transaction(function () use ($rows, $userId) {
            foreach (array_chunk($rows, 50) as $chunk) DB::table('trial_state')->insert($chunk);
            Setting::set(self::SETTING, '1', $userId); // بعد نسخ الإعدادات: الإعادة تمحو العلامة معها
        });
        return ['tables' => count($rows)];
    }

    /**
     * الإنهاء: يحذف ما فوق خط الأساس في كل جدول، ثم يتيم الجداول بلا `id`، ثم يعيد النسختين ويطفئ الوضع.
     * ترتيب الحذف لا يُفترض: تمريرات متكررة، والجدول الذي يمنعه مفتاح أجنبي ينتظر التمريرة التالية.
     * @return array{deleted: array<string,int>, left: array<string,int>}
     */
    public function stop(): array
    {
        $base = DB::table('trial_state')->where('kind', 'baseline')->pluck('value', 'name')->map(fn ($v) => (int) $v)->all();
        if (!$base && !DB::table('trial_state')->where('kind', 'snapshot')->exists()) throw new RuntimeException('لا تجربة مشغَّلة ولا خط أساس محفوظ.');

        // جدول أُنشئ بعد التشغيل (ترحيل أثناء التجربة): خط أساسه صفر
        foreach ($this->tables() as $t) {
            if (!isset($base[$t]) && !in_array($t, self::SNAPSHOT, true) && $this->hasIntId($t)) $base[$t] = 0;
        }

        $deleted = [];
        $pending = array_keys($base);
        for ($pass = 0; $pass < 12 && $pending; $pass++) {
            $next = [];
            foreach ($pending as $t) {
                if (!Schema::hasTable($t)) continue;
                try {
                    $n = DB::table($t)->where('id', '>', $base[$t])->delete();
                    if ($n) $deleted[$t] = ($deleted[$t] ?? 0) + $n;
                } catch (QueryException $e) {
                    $next[] = $t; // ابنٌ لم يُحذف بعد
                }
            }
            if ($next === $pending) break; // لا تقدّم
            $pending = $next;
        }

        foreach ($this->orphans() as $t => $n) $deleted[$t] = ($deleted[$t] ?? 0) + $n;

        DB::transaction(function () {
            foreach (self::SNAPSHOT as $t) {
                $snap = DB::table('trial_state')->where('kind', 'snapshot')->where('name', $t)->value('value');
                if ($snap === null) continue;
                $rows = json_decode($snap, true) ?: [];
                DB::table($t)->delete();
                foreach (array_chunk($rows, 20) as $chunk) DB::table($t)->insert($chunk);
            }
            DB::table('trial_state')->delete();
        });

        $left = [];
        foreach ($pending as $t) $left[$t] = DB::table($t)->where('id', '>', $base[$t])->count();
        return ['deleted' => $deleted, 'left' => array_filter($left)];
    }

    /** ما أُنشئ منذ التشغيل، لكل جدول — للعرض قبل الحذف */
    public function inventory(): array
    {
        $out = [];
        foreach (DB::table('trial_state')->where('kind', 'baseline')->pluck('value', 'name') as $t => $max) {
            if (!Schema::hasTable($t)) continue;
            $n = DB::table($t)->where('id', '>', (int) $max)->count();
            if ($n) $out[$t] = $n;
        }
        return $out;
    }

    /** الجداول بلا `id` (جداول الربط): يُحذف منها ما يشير إلى أبٍ لم يعد موجوداً */
    private function orphans(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            if (Schema::hasColumn($t, 'id') || in_array($t, self::SNAPSHOT, true)) continue;
            foreach (Schema::getForeignKeys($t) as $fk) {
                if (count($fk['columns']) !== 1) continue;
                [$col, $parent, $pcol] = [$fk['columns'][0], $fk['foreign_table'], $fk['foreign_columns'][0]];
                $n = DB::table($t)->whereNotNull($col)->whereNotIn($col, DB::table($parent)->select($pcol))->delete();
                if ($n) $out[$t] = ($out[$t] ?? 0) + $n;
            }
        }
        return $out;
    }

    /** خط الأساس لعمود `id` الرقمي وحده: معرّف نصي (uuid) لا يُقارن بـ«أكبر من» */
    private function hasIntId(string $t): bool
    {
        return Schema::hasColumn($t, 'id') && str_contains(strtolower(Schema::getColumnType($t, 'id')), 'int');
    }

    /** @return string[] */
    private function tables(): array
    {
        $names = array_map(fn ($t) => $t['name'], Schema::getTables());
        return array_values(array_filter($names, fn ($t) => !in_array($t, self::SKIP, true) && !str_starts_with($t, 'pg_') && !str_starts_with($t, 'sqlite_')));
    }
}
