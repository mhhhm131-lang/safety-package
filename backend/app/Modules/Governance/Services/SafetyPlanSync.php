<?php

namespace App\Modules\Governance\Services;

use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\SafetyPlan;
use App\Modules\Governance\Models\SafetyPlanTask;
use Illuminate\Support\Facades\DB;

/**
 * زرع خطط السلامة من وثائق المعهد `HZ-0x/safety-plan.html` (المرحلة ٣٠-١، قرار ٨٩ «كلام المكان») على مثال `ResponsePlanSync`.
 *
 * الوثيقة للصنف، وتُقرأ **مرة** فتُزرع منها صفوف الجدولين لكل مكان من صنفها في كل مبنى، ثم تُعدَّل من الشاشة والنظام يقرأ الصفوف.
 * البصمة (sha1) تمنع إعادة القراءة إن لم تتغير الوثيقة. وإن تغيّرت: الصف غير المعدَّل يتبعها، والصف المعدَّل يحتفظ بكلامه
 * ويحدَّث أصله (`source`) فقط، والجديد يُزرع، وغير المعدَّل الغائب يُحذف. لا كتابة عكسية في الوثيقة أبداً.
 *
 * مصدر الملفات: مجلد المستودع الأعلى محلياً (الأصل)، وpublic/ على الخادم، أو مجلد يُمرَّر صراحةً (اختبار «تعديل تجريبي في نسخة»).
 */
class SafetyPlanSync
{
    public const FILE = 'safety-plan.html';

    /** رؤوس الجدولين كما في الوثيقة — تُطابَق حرفياً وإلا رُفضت القراءة (الشكل للصنف). */
    private const HEADERS = [
        'proactive' => ['المهمة', 'المسؤول', 'متى', 'كيف'],
        'operational' => ['المهمة', 'المسؤول', 'التكرار', 'التوثيق'],
    ];

    /** خانات كل طبقة بترتيب أعمدة الوثيقة بعد «المهمة». */
    private const FIELDS = [
        'proactive' => ['responsible', 'when_text', 'how_text'],
        'operational' => ['responsible', 'frequency', 'documentation'],
    ];

    /**
     * كل الأماكن الفعّالة في كل المباني (الصنف الغائب في المبنى معطَّل فلا خطة له).
     * @return array<string, array{status:string, tasks?:int, edited?:int, path?:string}>  status: synced | unchanged | missing
     */
    public function sync(?string $from = null, bool $force = false): array
    {
        $result = [];
        foreach (Place::active()->orderBy('building_id')->orderBy('sort')->get() as $place) {
            $result[$place->code] = $this->syncPlace($place, $from, $force);
        }
        return $result;
    }

    /** مكان واحد — رخيصة (sha1 لملف واحد) فتُستدعى عند فتح ملف المكان وشاشة خطته. */
    public function syncPlace(Place $place, ?string $from = null, bool $force = false): array
    {
        $path = $this->resolvePath($place->category, $from);
        if (!$path) return ['status' => 'missing'];
        $html = file_get_contents($path);
        $fingerprint = sha1($html);
        $plan = SafetyPlan::firstOrNew(['place_id' => $place->id]);
        if ($plan->exists && !$force && $plan->fingerprint === $fingerprint && $plan->source_path === $path) {
            return ['status' => 'unchanged', 'tasks' => $plan->tasks_count, 'edited' => $plan->edited_count, 'path' => $path];
        }
        $parsed = $this->parse($html);
        DB::transaction(function () use ($plan, $parsed, $path, $fingerprint, $place) {
            $plan->fill(['title' => $parsed['title'] ?: $place->name, 'source_path' => $path, 'fingerprint' => $fingerprint, 'synced_at' => now()]);
            $plan->save();
            $existing = SafetyPlanTask::where('plan_id', $plan->id)->get()->keyBy(fn ($t) => $t->layer.'|'.$t->task_key);
            $seen = [];
            foreach ($parsed['tasks'] as $row) {
                $k = $row['layer'].'|'.$row['task_key'];
                $seen[$k] = true;
                $source = [];
                foreach (SafetyPlanTask::WORD_FIELDS as $f) $source[$f] = $row[$f] ?? null;
                if ($t = $existing->get($k)) {
                    $t->source = $source;
                    $t->sort = $row['sort'];
                    if (!$t->isEdited()) $t->fill($source); // غير المعدَّل يتبع الوثيقة؛ المعدَّل يحتفظ بكلامه
                    $t->save();
                } else {
                    SafetyPlanTask::create($row + ['plan_id' => $plan->id, 'source' => $source]);
                }
            }
            foreach ($existing as $k => $t) if (!isset($seen[$k]) && !$t->isEdited()) $t->delete();
            $plan->refreshCounts();
        });
        return ['status' => 'synced', 'tasks' => $plan->tasks_count, 'edited' => $plan->edited_count, 'path' => $path];
    }

    /** مسار وثيقة الصنف: المجلد المُمرَّر ← مجلد المستودع الأعلى ← public/. */
    public function resolvePath(string $category, ?string $from = null): ?string
    {
        $folder = Place::FOLDERS[$category] ?? null;
        if (!$folder) return null;
        $candidates = $from !== null
            ? [rtrim($from, '/\\').DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.self::FILE]
            : [dirname(base_path()).DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.self::FILE, public_path($folder.DIRECTORY_SEPARATOR.self::FILE)];
        foreach ($candidates as $p) if (is_file($p)) return $p;
        return null;
    }

    /**
     * قراءة وثيقة واحدة. الجدولان شبكتان (grid) بأنماط سطرية ثابتة:
     *   رأس الجدول: ٤ خلايا `background:#1E3329;padding:9px 12px` · الصف: ٤ خلايا `background:#FFFFFF;padding:9px 12px`
     *   عنوان الطبقة: «الطبقة الاستباقية …» ثم «الطبقة التشغيلية …».
     * @return array{title:string, tasks:array<int, array>}
     */
    public function parse(string $html): array
    {
        $start = strpos($html, 'id="doc"');
        $doc = $start === false ? $html : substr($html, $start);

        $title = '';
        if (preg_match('/flex:1;background:#16181C;color:#F6F3EC;[^"]*">([^<]+)<\/div>/u', $doc, $m)) $title = $this->text($m[1]);

        $pPro = strpos($doc, 'الطبقة الاستباقية');
        $pOp = strpos($doc, 'الطبقة التشغيلية');
        if ($pPro === false || $pOp === false || $pOp < $pPro) throw new \RuntimeException('وثيقة خطة السلامة بلا طبقتيها بالترتيب.');
        $sections = ['proactive' => substr($doc, $pPro, $pOp - $pPro), 'operational' => substr($doc, $pOp)];

        $tasks = [];
        $sort = 0;
        foreach ($sections as $layer => $content) {
            preg_match_all('/<div style="background:#1E3329;padding:9px 12px;[^"]*">(.*?)<\/div>/su', $content, $heads);
            $labels = array_map(fn ($h) => $this->text($h), $heads[1]);
            if (array_slice($labels, 0, 4) !== self::HEADERS[$layer]) {
                throw new \RuntimeException("رأس جدول «$layer» لا يطابق الوثيقة: ".implode('، ', $labels));
            }
            preg_match_all('/<div style="background:#FFFFFF;padding:9px 12px;[^"]*">(.*?)<\/div>/su', $content, $cells);
            $cells = array_map(fn ($c) => $this->text($c), $cells[1]);
            if (count($cells) % 4 !== 0) throw new \RuntimeException("خلايا جدول «$layer» ليست من مضاعفات أربعة: ".count($cells));
            $keys = [];
            foreach (array_chunk($cells, 4) as $chunk) {
                [$task, $a, $b, $c] = $chunk;
                if ($task === '') continue;
                $key = sha1($task);
                $keys[$key] = ($keys[$key] ?? 0) + 1;
                if ($keys[$key] > 1) $key .= '#'.$keys[$key]; // مهمة مكرّرة نصاً في الطبقة نفسها
                [$f1, $f2, $f3] = self::FIELDS[$layer];
                $tasks[] = ['layer' => $layer, 'sort' => ++$sort, 'task_key' => $key, 'task' => $task,
                    $f1 => $a ?: null, $f2 => $b ?: null, $f3 => $c ?: null];
            }
        }
        return ['title' => $title, 'tasks' => $tasks];
    }

    private function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/iu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
}
