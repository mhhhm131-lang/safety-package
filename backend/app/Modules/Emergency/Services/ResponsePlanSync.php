<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Emergency\Support\RoleCards;
use App\Modules\Governance\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة خطط الاستجابة الثماني من وثائق المعهد `HZ-0x/response-plan.html` (المرحلة ١٠-١، المكوّن أ).
 *
 * الوثيقة هي الحقيقة (كما `ipa-place` ← TeamSync). تُقرأ وتُشتق منها لكل مكان مساراته وخطواته:
 *   الكشف والبلاغ (٠) · السيناريوهات (أ/ب) · المسار الطبي · مسار المكان (الحريق…) · حالات أخرى.
 * لكل خطوة: الترتيب، الرقم كما هو، العنوان، «متى» نصاً ونافذةً بالثواني، «من» نصاً وبطاقةً، «أين»، «كيف».
 * لا كتابة عكسية أبداً. البصمة (sha1) تمنع إعادة القراءة إن لم تتغير الوثيقة.
 *
 * مصدر الملفات: مجلد المستودع الأعلى محلياً (الأصل)، وpublic/ على الخادم (Dockerfile ينسخ المجلدات إليه)،
 * أو مجلد يُمرَّر صراحةً (اختبار «تعديل تجريبي في نسخة من وثيقة»).
 */
class ResponsePlanSync
{
    public const FILE = 'response-plan.html';

    /** نوافذ «متى» بالثواني كما في وثيقة التصميم: «الثانية الأولى» = ٠–٥ ث. «٠» وحدها (HZ-01 المسار الطبي ٢) تُقرأ كذلك. */
    private const FIRST_SECOND = [0, 5];

    /**
     * @return array<string, array{status:string, steps?:int, declared?:?int, no_card?:int, path?:string}>
     *   status: synced | unchanged | missing
     */
    public function sync(?string $from = null, bool $force = false): array
    {
        $result = [];
        // ٢٨-٣ (قرار ٧٨): وثيقة الصنف لكل مكان منه في كل مبنى
        foreach (Place::where('category', '!=', 'HZ-00')->orderBy('building_id')->orderBy('sort')->get() as $place) {
            $result[$place->code] = $this->syncPlace($place, $from, $force);
        }
        return $result;
    }

    /**
     * مكان واحد — رخيصة (sha1 لملف واحد) فتُستدعى عند فتح ملف المكان وشاشة خطته.
     * ٣٠-٢ (قرار ٨٩): الصفوف بمفاتيحها (المسار + رقم الخطوة) لا تُحذف وتُزرع من جديد: الشكل (العنوان، متى، النافذة، الترتيب) يتبع
     * الوثيقة دائماً؛ كلام المكان (من، أين، كيف، الهاتف، «لا ينطبق هنا») يبقى في الصف المعدَّل ويحدَّث أصله فقط؛ غير المعدَّل يتبع
     * الوثيقة؛ الجديد يُزرع، وغير المعدَّل الغائب يُحذف. لا كتابة عكسية أبداً.
     */
    public function syncPlace(Place $place, ?string $from = null, bool $force = false): array
    {
        if ($place->category === 'HZ-00') return ['status' => 'missing'];
        $path = $this->resolvePath($place->category, $from);
        if (!$path) return ['status' => 'missing'];
        $html = file_get_contents($path);
        $fingerprint = sha1($html);
        $plan = ResponsePlan::firstOrNew(['place_id' => $place->id]);
        // خطة زُرعت قبل ٣٠-٢ صفوفها بلا مفتاح ولا أصل (كما على المنشور) تُعاد قراءتها ولو لم تتغير بصمتها — مرة واحدة
        $legacy = $plan->exists && ResponsePlanStep::where('plan_id', $plan->id)->whereNull('step_key')->exists();
        if ($plan->exists && !$force && !$legacy && $plan->fingerprint === $fingerprint && $plan->source_path === $path) {
            return ['status' => 'unchanged', 'steps' => $plan->steps_count, 'declared' => $plan->declared_total, 'no_card' => $plan->no_card_count, 'edited' => $plan->edited_count, 'path' => $path];
        }
        $parsed = $this->parse($html);
        DB::transaction(function () use ($plan, $parsed, $path, $fingerprint, $place) {
            $plan->fill(['title' => $parsed['title'] ?: $place->name, 'declared_total' => $parsed['declared_total'],
                'source_path' => $path, 'fingerprint' => $fingerprint, 'synced_at' => now()]);
            $plan->save();
            $existing = ResponsePlanStep::where('plan_id', $plan->id)->whereNotNull('step_key')->get()->keyBy('step_key');
            $seen = [];
            foreach ($parsed['steps'] as $s) {
                $seen[$s['step_key']] = true;
                $source = array_intersect_key($s, array_flip(ResponsePlanStep::SOURCE_FIELDS)) + ['phone_text' => null];
                $shape = array_diff_key($s, array_flip(ResponsePlanStep::SOURCE_FIELDS));
                if ($t = $existing->get($s['step_key'])) {
                    $t->fill($shape);
                    $t->source = $source;
                    if (!$t->isEdited()) $t->fill($source); // غير المعدَّل يتبع الوثيقة؛ المعدَّل يحتفظ بكلامه وبطاقاته
                    $t->save();
                } else {
                    ResponsePlanStep::create($s + ['plan_id' => $plan->id, 'source' => $source]);
                }
            }
            foreach ($existing as $k => $t) if (!isset($seen[$k]) && !$t->isEdited()) $t->delete();
            ResponsePlanStep::where('plan_id', $plan->id)->whereNull('step_key')->delete(); // صفوف ما قبل ٣٠-٢ بلا مفتاح: لم تُعدَّل قط
            $plan->refreshCounts();
        });
        return ['status' => 'synced', 'steps' => $plan->steps_count, 'declared' => $plan->declared_total, 'no_card' => $plan->no_card_count, 'edited' => $plan->edited_count, 'path' => $path];
    }

    /** مسار وثيقة المكان: المجلد المُمرَّر ← مجلد المستودع الأعلى ← public/. */
    public function resolvePath(string $code, ?string $from = null): ?string
    {
        $folder = Place::FOLDERS[$code] ?? null;
        if (!$folder) return null;
        $candidates = $from !== null
            ? [rtrim($from, '/\\').DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.self::FILE]
            : [dirname(base_path()).DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.self::FILE, public_path($folder.DIRECTORY_SEPARATOR.self::FILE)];
        foreach ($candidates as $p) if (is_file($p)) return $p;
        return null;
    }

    /**
     * قراءة وثيقة واحدة. الوثائق مولَّدة بأنماط سطرية ثابتة (doc-page)، فالقراءة بأنماط نصية على تلك الأنماط:
     *   رأس القسم: div بخلفية + padding:14px 24px + font-size:15px;color:#1E3329 وفيه <span>· نوع المسار · N خطوة</span>
     *   الخطوة: خلية الرقم (font-size:22px) + «متى» (font-size:11.5px) ثم خلية العنوان (font-weight:700;font-size:14px) والتفصيل (من · أين · كيف)
     *   الكشف: بنود ①②③ (margin-bottom:12px) وسطر «← المركز…» (border-radius:6px)
     * ألوان الأرقام والعناوين تختلف بين المسارات (#2D4A3E، #2A5A8A، #6E5A1F) فلا تُقيَّد.
     *
     * @return array{title:string, declared_total:?int, steps:array<int, array>}
     */
    public function parse(string $html): array
    {
        $start = strpos($html, 'id="doc"');
        $doc = $start === false ? $html : substr($html, $start);

        $title = '';
        if (preg_match('/flex:1;background:#16181C;color:#F6F3EC;[^"]*">([^<]+)<\/div>/u', $doc, $m)) $title = $this->text($m[1]);
        $declared = null;
        if (preg_match('/([٠-٩0-9]+)\s*خطوة\s*<\/div>/u', $doc, $m)) $declared = $this->int($m[1]);

        // رؤوس الأقسام بمواضعها
        preg_match_all('/<div style="background:#[0-9A-Fa-f]{6};padding:14px 24px;font-family:\'Noto Kufi Arabic\',sans-serif;font-weight:700;font-size:15px;color:#1E3329;[^"]*">(.*?)<\/div>/su', $doc, $heads, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $raw = []; // [offset, step]
        foreach ($heads as $i => $h) {
            $sectionStart = $h[0][1] + strlen($h[0][0]);
            $sectionEnd = isset($heads[$i + 1]) ? $heads[$i + 1][0][1] : strlen($doc);
            $content = substr($doc, $sectionStart, $sectionEnd - $sectionStart);
            $inner = $h[1][0];
            $span = preg_match('/<span[^>]*>(.*?)<\/span>/su', $inner, $sm) ? $this->text($sm[1]) : '';
            $pathTitle = $this->text(preg_replace('/<span.*$/su', '', $inner));
            $pathKey = $this->pathKey($pathTitle, $span);
            $pathDeclared = preg_match('/([٠-٩0-9]+)\s*خطو/u', $span, $cm) ? $this->int($cm[1]) : null;

            // الخطوات المرقّمة (والسيناريوهات أ/ب بالبنية نفسها)
            preg_match_all('/font-size:22px;color:#[0-9A-Fa-f]{6};">([^<]+)<\/div><div style="font-size:11\.5px;color:#5A5548;margin-top:3px;">([^<]*)<\/div><\/div>\s*<div style="padding:14px 20px;[^"]*"><div style="font-weight:700;font-size:14px;color:#[0-9A-Fa-f]{6};margin-bottom:4px;">(.*?)<\/div><div style="font-size:13px;line-height:1\.7;color:#5A5548;">(.*?)<\/div><\/div>/su', $content, $rows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($rows as $r) {
                $when = $this->text($r[2][0]);
                [$from, $to, $conditional] = $this->window($when);
                $d = $this->details($r[4][0]);
                $cards = RoleCards::match($d['who']);
                $raw[] = [$sectionStart + $r[0][1], [
                    'path_key' => $pathKey, 'path_title' => $pathTitle, 'path_declared_count' => $pathDeclared,
                    'label' => $this->text($r[1][0]), 'title' => $this->text($r[3][0]),
                    'when_text' => $when ?: null, 'window_from_sec' => $from, 'window_to_sec' => $to, 'is_conditional' => $conditional,
                    'who_text' => $d['who'], 'where_text' => $d['where'], 'how_text' => $d['how'],
                    'role_cards' => $cards, 'role_card_no' => $cards[0] ?? null,
                ]];
            }

            // بنود الكشف والبلاغ ①②③ — في قسم الكشف فقط
            if ($pathKey === 'detection') {
                preg_match_all('/<div style="margin-bottom:12px;">(.*?)<\/div>/su', $content, $items, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($items as $it) {
                    $t = $this->text($it[1][0]);
                    $label = mb_substr($t, 0, 1);
                    $rest = trim(mb_substr($t, 1));
                    [$who, $how] = array_pad(explode(':', $rest, 2), 2, '');
                    $cards = RoleCards::match($who);
                    $raw[] = [$sectionStart + $it[0][1], [
                        'path_key' => 'detection', 'path_title' => $pathTitle, 'path_declared_count' => null,
                        'label' => $label, 'title' => $rest, 'when_text' => 'يسبق كل المسارات',
                        'window_from_sec' => null, 'window_to_sec' => null, 'is_conditional' => false,
                        'who_text' => trim($who) ?: null, 'where_text' => null, 'how_text' => trim($how) ?: null,
                        'role_cards' => $cards, 'role_card_no' => $cards[0] ?? null,
                    ]];
                }
            }
            // سطر «← المركز يُنادي…» — يتبع الكشف وقد يأتي بعد السيناريوهات (الكهرباء، التكييف، مركز البيانات)
            if (in_array($pathKey, ['detection', 'scenario'], true)) {
                preg_match_all('/<div style="background:#2D4A3E;color:#F6F3EC;padding:10px 16px;border-radius:6px;[^"]*">(.*?)<\/div>/su', $content, $arrows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($arrows as $a) {
                    $t = $this->text($a[1][0]);
                    $rest = trim(ltrim($t, '← '));
                    $cards = RoleCards::match('المركز'); // النداء الأول من المركز = المناوب ٢١؛ المنادى عليهم في النص لا في «من»
                    $raw[] = [$sectionStart + $a[0][1], [
                        'path_key' => 'detection', 'path_title' => 'الكشف والبلاغ', 'path_declared_count' => null,
                        'label' => '←', 'title' => $rest, 'when_text' => 'فور البلاغ',
                        'window_from_sec' => self::FIRST_SECOND[0], 'window_to_sec' => self::FIRST_SECOND[1], 'is_conditional' => false,
                        'who_text' => 'المركز', 'where_text' => null, 'how_text' => $rest,
                        'role_cards' => $cards, 'role_card_no' => $cards[0] ?? null,
                    ]];
                }
            }
        }

        usort($raw, fn ($a, $b) => $a[0] <=> $b[0]);
        $steps = [];
        $keys = [];
        foreach ($raw as $i => [, $s]) {
            // ٣٠-٢: مفتاح الصف = المسار + رقم الخطوة كما في الوثيقة (والمكرّر — كسطر «←» الثاني — بترتيبه)
            $key = sha1($s['path_key'].'|'.$s['label']);
            $keys[$key] = ($keys[$key] ?? 0) + 1;
            if ($keys[$key] > 1) $key .= '#'.$keys[$key];
            $steps[] = $s + ['sort' => $i + 1, 'step_key' => $key];
        }
        return ['title' => $title, 'declared_total' => $declared, 'steps' => $steps];
    }

    private function pathKey(string $title, string $span): string
    {
        if (str_contains($title, 'الكشف')) return 'detection';
        if (str_contains($title, 'سيناريو') || str_contains($span, 'نوعان')) return 'scenario';
        if (str_contains($span, 'حالات أخرى')) return 'other';
        if (str_contains($span, 'المسار الطبي') || str_contains($title, 'الطبي')) return 'medical';
        return 'fire';
    }

    /** «من: … · أين: … · كيف: …» */
    private function details(string $html): array
    {
        $who = $where = $how = null;
        if (preg_match('/من:<\/strong>\s*(.*?)\s*·\s*<strong[^>]*>أين:<\/strong>\s*(.*?)\s*·\s*<strong[^>]*>كيف:<\/strong>\s*(.*)$/su', $html, $m)) {
            $who = $this->text($m[1]); $where = $this->text($m[2]); $how = $this->text($m[3]);
        } elseif (preg_match('/من:<\/strong>\s*(.*?)(?:\s*·\s*<strong|$)/su', $html, $m)) {
            $who = $this->text($m[1]); $how = $this->text($html);
        } else {
            $how = $this->text($html);
        }
        return ['who' => $who ?: null, 'where' => $where ?: null, 'how' => $how ?: null];
    }

    /**
     * «متى» ← نافذة بالثواني. «الثانية الأولى»/«فوراً»/«٠» = ٠–٥ ث؛ «أ–ب ثانية/دقيقة/ساعة» = مدى؛ غير ذلك شرطية بلا نافذة.
     * @return array{0:?int,1:?int,2:bool}
     */
    public function window(string $when): array
    {
        $w = trim($this->digits($when));
        $w = str_replace(['–', '—', '-'], '-', $w);
        if ($w === '' ) return [null, null, false];
        if ($w === '0' || str_contains($w, 'الثانية الأولى') || $w === 'فوراً' || $w === 'فورا') return [self::FIRST_SECOND[0], self::FIRST_SECOND[1], false];
        if (preg_match('/^(\d+)\s*-\s*(\d+)\s*(ثانية|ثوان|ثواني|ث|دقيقة|دقائق|د|ساعة|ساعات|س)$/u', $w, $m)) {
            $unit = match (true) { str_starts_with($m[3], 'د') => 60, str_starts_with($m[3], 'س') => 3600, default => 1 };
            return [(int) $m[1] * $unit, (int) $m[2] * $unit, false];
        }
        return [null, null, true];
    }

    private function text(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    private function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private function int(string $s): int { return (int) $this->digits($s); }
}
