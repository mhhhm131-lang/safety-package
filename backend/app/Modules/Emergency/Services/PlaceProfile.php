<?php

namespace App\Modules\Emergency\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة ١٩-٥ (قرار ٤٨): ملف المكان (وثيقة ipa-place) قراءةً وكتابةً من الخلفية — منطق اللوحة حرفياً (dashboard.html:848-1086).
 * الوثيقة تبقى بصيغتها ومصدراً واحداً للّوحة والخلفية حتى ١٩-٧؛ كل كتابة ترفع النسخة وتشتق الفرق (TeamSync).
 * الصلاحيات هنا في الخادم كما كانت في المتصفح: canPlans / canUnit / الاعتماد لمدير الشؤون / الفعاليات ورئيس الأمن والسلامة.
 *
 *   { "HZ-xx": { plans:{sa,saBy,ra,drill}, units:{ "<uid>": {team[4],nom,appr,hr,dept,staff,more:[{team,nom,appr,hr}]} }, events:[{name,date,team[4],nom,appr}] } }
 */
class PlaceProfile
{
    public const KEY = TeamSync::KEY;
    public const HUB = 'HZ-06';
    public const HALLS = 'HZ-07';
    public const PER_TEAM = 25;
    public const TEAM = ['المنسق', 'المسعف', 'المنقذ', 'الإطفائي'];

    /** حالة الفريق: اللون والنص (dashboard.html:871 UST) */
    public const UST = [
        'hr' => ['ok', 'معتمد ومُحال للموارد البشرية'], 'appr' => ['ok', 'معتمد'], 'nom' => ['warn', 'مرشَّح — بانتظار الاعتماد'],
        'part' => ['warn', 'ينقص فريق'], 'none' => ['bad', 'لم يُرشَّح'],
    ];

    private const EMPTY_TEAM = ['team' => [], 'nom' => [], 'appr' => [], 'hr' => []];

    // ── القراءة ──

    public static function all(): array
    {
        $data = json_decode((string) InstituteDocument::where('key', self::KEY)->value('data'), true);
        return is_array($data) ? $data : [];
    }

    public static function get(string $hz): array
    {
        return self::normalize(self::all()[$hz] ?? []);
    }

    /** placeGet: الصيغة القديمة (فريق على مستوى المكان) تصير الوحدة «_»، وكل وحدة بخاناتها الأربع */
    public static function normalize($p): array
    {
        $p = is_array($p) ? $p : [];
        $p['plans'] = is_array($p['plans'] ?? null) ? $p['plans'] : [];
        $p['units'] = is_array($p['units'] ?? null) ? $p['units'] : [];
        if (isset($p['team'])) {
            $named = is_array($p['team']) && array_filter($p['team'], fn ($t) => is_array($t) && !empty($t['name']));
            if (!isset($p['units']['_']) && ($named || !empty($p['nom']['date']))) {
                $p['units']['_'] = ['team' => $p['team'], 'nom' => $p['nom'] ?? [], 'appr' => $p['appr'] ?? [], 'hr' => $p['hr'] ?? []];
            }
            unset($p['team'], $p['nom'], $p['appr'], $p['hr']);
        }
        foreach ($p['units'] as $k => $u) $p['units'][$k] = self::slots(is_array($u) ? $u : []);
        return $p;
    }

    private static function slots(array $t): array
    {
        foreach (['team', 'nom', 'appr', 'hr'] as $f) $t[$f] = is_array($t[$f] ?? null) ? $t[$f] : [];
        return $t;
    }

    /** إدارات المكان كما تراها اللوحة من ipa-depts (DeptSync::toDocument): الفعّالة، وبلا مكان = المكاتب الإدارية */
    private static function depts(string $hz): array
    {
        return OrganizationUnit::with('place', 'manager')->where('is_active', true)->orderBy('order')->orderBy('id')->get()
            ->filter(fn (OrganizationUnit $u) => ($u->place?->code ?? self::HUB) === $hz)
            ->map(fn (OrganizationUnit $u) => ['id' => (string) $u->code, 'name' => $u->name, 'mgr' => $u->manager?->name ?? ($u->manager_name ?? '')])->values()->all();
    }

    /** unitList: في المكاتب الإدارية وحدة لكل إدارة؛ وفي غيرها وحدة «_» أو وحدة لكل إدارة تشغل المكان (والقديمة «_» تبقى للأولى) */
    public static function unitList(string $hz, array $p): array
    {
        $ds = self::depts($hz);
        $row = fn (string $uid, array $d) => ['uid' => $uid, 'dept' => $d['id'], 'label' => $d['name'], 'mgr' => $d['mgr']];
        if ($hz === self::HUB) return array_map(fn ($d) => $row($d['id'], $d), $ds);
        if (!$ds) {
            $code = (string) ($p['units']['_']['dept'] ?? '');
            $dd = $code !== '' ? OrganizationUnit::with('manager')->where('code', $code)->where('is_active', true)->first() : null;
            return [['uid' => '_', 'dept' => $dd ? (string) $dd->code : '', 'label' => $dd?->name ?? 'الإدارة المشغّلة للمكان', 'mgr' => $dd ? ($dd->manager?->name ?? ($dd->manager_name ?? '')) : '']];
        }
        $out = [];
        foreach ($ds as $i => $d) $out[] = $row(($i === 0 && isset($p['units']['_']) && !isset($p['units'][$d['id']])) ? '_' : $d['id'], $d);
        return $out;
    }

    public static function unit(array $p, string $uid): array
    {
        return self::slots(is_array($p['units'][$uid] ?? null) ? $p['units'][$uid] : []);
    }

    public static function teamState(array $t): string
    {
        return !empty($t['hr']['date']) ? 'hr' : (!empty($t['appr']['date']) ? 'appr' : (!empty($t['nom']['date']) ? 'nom' : 'none'));
    }

    public static function teamsNeeded(array $u): int
    {
        $n = (int) ($u['staff'] ?? 0);
        return $n > 0 ? (int) ceil($n / self::PER_TEAM) : 1;
    }

    /** الفريق الأول في جذر الوحدة والباقي في more بترتيبه */
    public static function teamPeek(array $u, int $k): array
    {
        $t = $k ? ($u['more'][$k - 1] ?? []) : $u;
        return self::slots(is_array($t) ? $t : []);
    }

    /** المعروض: المطلوب بالعدد، أو أكثر إن بقي فريق زائد مرشَّح بعد نقص العدد */
    public static function teamCount(array $u): int
    {
        $last = 0;
        foreach (array_values((array) ($u['more'] ?? [])) as $i => $t) {
            if (is_array($t) && (self::named($t['team'] ?? []) || !empty($t['nom']['date']))) $last = $i + 1;
        }
        return max(self::teamsNeeded($u), $last + 1);
    }

    public static function unitStateAll(array $u): string
    {
        $s = [];
        for ($k = 0; $k < self::teamsNeeded($u); $k++) $s[] = self::teamState(self::teamPeek($u, $k));
        $every = fn (array $in) => !array_diff($s, $in);
        if ($every(['hr'])) return 'hr';
        if ($every(['hr', 'appr'])) return 'appr';
        if ($every(['none'])) return 'none';
        return in_array('none', $s, true) ? 'part' : 'nom';
    }

    public static function named($team): int
    {
        return count(array_filter(is_array($team) ? $team : [], fn ($t) => is_array($t) && trim((string) ($t['name'] ?? '')) !== ''));
    }

    public static function events(array $p): array
    {
        $ev = array_values(array_filter(is_array($p['events'] ?? null) ? $p['events'] : [], 'is_array'));
        return array_map(function (array $e) {
            foreach (['team', 'nom', 'appr'] as $f) $e[$f] = is_array($e[$f] ?? null) ? $e[$f] : [];
            return $e;
        }, $ev);
    }

    // ── الصلاحيات (كما في اللوحة، لكن في الخادم) ──

    private static function ui(User $user): ?string
    {
        $profile = $user->profile;
        return $profile && $profile->is_active ? PermissionRegistry::uiRole($profile->role) : null;
    }

    /** الخطتان والإحالة: مسؤول السلامة ومدير الشؤون الإدارية والهندسية */
    public static function canPlans(User $user): bool
    {
        return in_array(self::ui($user), ['safety', 'adm'], true);
    }

    /** الترشيح وعدد الموظفين: هما، ومدير الوحدة لوحدته */
    public static function canUnit(User $user, array $un): bool
    {
        if (self::canPlans($user)) return true;
        $mine = $user->profile?->organizationUnit;
        return self::ui($user) === 'dept' && $mine && $mine->is_active && $un['dept'] !== '' && (string) $mine->code === $un['dept'];
    }

    /** اعتماد الفريق: مدير الشؤون الإدارية والهندسية وحده */
    public static function canApprove(User $user): bool
    {
        return self::ui($user) === 'adm';
    }

    /** فرق الفعاليات: هما، وإدارة مكانها القاعات */
    public static function canEvents(User $user): bool
    {
        if (self::canPlans($user)) return true;
        $mine = $user->profile?->organizationUnit;
        return self::ui($user) === 'dept' && $mine && $mine->is_active && ($mine->place?->code ?? self::HUB) === self::HALLS;
    }

    /** اعتماد فريق الفعالية: رئيس الأمن والسلامة وحده (قرار ٤٣) */
    public static function canApproveEvent(User $user): bool
    {
        return self::ui($user) !== null && $user->profile->role === 'security_safety_head';
    }

    // ── الكتابة ──

    /** تعديل ملف مكان واحد داخل معاملة بقفل، ثم رفع النسخة واشتقاق الفرق. $fn يعدّل $p بالمرجع وقد يرمي abort. */
    public function mutate(string $hz, int $userId, callable $fn): void
    {
        DB::transaction(function () use ($hz, $userId, $fn) {
            $doc = InstituteDocument::where('key', self::KEY)->lockForUpdate()->first() ?? new InstituteDocument(['key' => self::KEY, 'version' => 0]);
            $all = json_decode((string) $doc->data, true);
            $all = is_array($all) ? $all : [];
            $p = self::normalize($all[$hz] ?? []);
            $fn($p);
            $all[$hz] = $p;
            $doc->data = json_encode(self::objectify($all), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $doc->version = $doc->version + 1;
            $doc->updated_by = $userId;
            $doc->save();
        });
        try {
            app(TeamSync::class)->sync();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * الخرائط تبقى كائنات JSON حتى وهي فارغة — اللوحة تكتب فيها بمفاتيح نصية (`p.units._`, `u.nom.by`)،
     * والمصفوفة في JavaScript تُسقط هذه المفاتيح عند الحفظ. القوائم (team, more, events) تبقى قوائم.
     */
    private static function objectify(array $all): object
    {
        $team = function (array $t) {
            foreach (['nom', 'appr', 'hr'] as $f) if (array_key_exists($f, $t)) $t[$f] = (object) (is_array($t[$f]) ? $t[$f] : []);
            return $t;
        };
        foreach ($all as $hz => $p) {
            if (!is_array($p)) continue;
            if (array_key_exists('plans', $p)) $p['plans'] = (object) (is_array($p['plans']) ? $p['plans'] : []);
            $units = [];
            foreach ((array) ($p['units'] ?? []) as $uid => $u) {
                if (!is_array($u)) continue;
                $u = $team($u);
                if (isset($u['more']) && is_array($u['more'])) $u['more'] = array_map(fn ($m) => is_array($m) ? $team($m) : $m, array_values($u['more']));
                $units[(string) $uid] = $u;
            }
            $p['units'] = (object) $units;
            if (isset($p['events']) && is_array($p['events'])) $p['events'] = array_map(fn ($e) => is_array($e) ? $team($e) : $e, array_values($p['events']));
            $all[$hz] = $p;
        }
        return (object) $all;
    }

    /** موضع الفريق k داخل الوحدة بالمرجع (teamAt) */
    private static function &teamAt(array &$unit, int $k): array
    {
        if (!$k) return $unit;
        $unit['more'] = array_values(is_array($unit['more'] ?? null) ? $unit['more'] : []);
        while (count($unit['more']) < $k) $unit['more'][] = self::EMPTY_TEAM;
        $unit['more'][$k - 1] = self::slots(is_array($unit['more'][$k - 1]) ? $unit['more'][$k - 1] : []);
        return $unit['more'][$k - 1];
    }

    /** أربعة صفوف بترتيب الأدوار من مدخلات النموذج */
    private static function rows(array $input, array $fields): array
    {
        $rows = [];
        foreach (self::TEAM as $i => $role) {
            $in = is_array($input[$i] ?? null) ? $input[$i] : [];
            $row = ['role' => $role];
            foreach ($fields as $f) $row[$f] = mb_substr(trim((string) ($in[$f] ?? '')), 0, 120);
            $row['user'] = mb_strtolower($row['user']);
            $rows[] = $row;
        }
        return $rows;
    }

    private static function nameList(array $team): array
    {
        return array_map(fn ($i) => trim((string) ($team[$i]['name'] ?? '')), [0, 1, 2, 3]);
    }

    public static function staffNumber($v): int
    {
        return min(100000, max(0, (int) strtr(trim(is_scalar($v) ? (string) $v : ''), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])));
    }

    /** saveUnit: الترشيح أو تعديله — تغيير الأسماء بعد الاعتماد يُلغي الاعتماد والإحالة */
    public function saveTeam(string $hz, array $un, int $k, array $in, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($un, $k, $in) {
            $unit = self::unit($p, $un['uid']);
            $t = &self::teamAt($unit, $k);
            $team = self::rows((array) ($in['team'] ?? []), ['name', 'user', 'dept', 'phone', 'trained', 'trainer']);
            if (!empty($t['appr']['date']) && self::nameList($team) !== self::nameList($t['team'])) {
                $t['appr'] = [];
                $t['hr'] = [];
            }
            $t['team'] = $team;
            $t['nom'] = self::named($team)
                ? ['by' => mb_substr(trim((string) ($in['nom_by'] ?? '')), 0, 120), 'dept' => $un['dept'], 'date' => trim((string) ($in['nom_date'] ?? '')) ?: now()->toDateString()]
                : [];
            unset($t);
            $unit['dept'] = $un['dept'] ?: ($unit['dept'] ?? '');
            if (array_key_exists('staff', $in)) self::setStaff($unit, $in['staff']);
            $p['units'][$un['uid']] = $unit;
        });
    }

    private static function setStaff(array &$unit, $v): void
    {
        $n = self::staffNumber($v);
        if ($n > 0) $unit['staff'] = $n;
        else unset($unit['staff']);
    }

    public function saveStaff(string $hz, array $un, $staff, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($un, $staff) {
            $unit = self::unit($p, $un['uid']);
            self::setStaff($unit, $staff);
            $p['units'][$un['uid']] = $unit;
        });
    }

    /** approveTeam / referHR — $step: appr أو hr */
    public function stamp(string $hz, array $un, int $k, string $step, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($un, $k, $step) {
            $unit = self::unit($p, $un['uid']);
            $t = &self::teamAt($unit, $k);
            $state = self::teamState($t);
            if ($step === 'appr') {
                abort_unless($state === 'nom' && self::named($t['team']), 422, 'لا ترشيح بأسماء ينتظر الاعتماد.');
                $t['appr'] = ['by' => 'مدير الشؤون الإدارية والهندسية', 'date' => now()->toDateString()];
            } else {
                abort_unless($state === 'appr', 422, 'الإحالة بعد الاعتماد.');
                $t['hr'] = ['date' => now()->toDateString()];
            }
            unset($t);
            $p['units'][$un['uid']] = $unit;
        });
    }

    public function savePlans(string $hz, array $in, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($in) {
            $v = fn (string $k) => mb_substr(trim((string) ($in[$k] ?? '')), 0, 120);
            $p['plans'] = ['sa' => $v('sa'), 'saBy' => $v('sa_by'), 'ra' => $v('ra'), 'drill' => $v('drill')];
        });
    }

    /** saveEvent: $i = null لفعالية جديدة — تعديل الأسماء يُلغي الاعتماد */
    public function saveEvent(string $hz, ?int $i, array $in, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($i, $in) {
            $ev = self::events($p);
            abort_if($i !== null && !isset($ev[$i]), 404);
            $e = $i !== null ? $ev[$i] : ['team' => [], 'nom' => [], 'appr' => []];
            $team = self::rows((array) ($in['team'] ?? []), ['name', 'user', 'dept', 'phone']);
            if (!empty($e['appr']['date']) && self::nameList($team) !== self::nameList($e['team'])) $e['appr'] = [];
            $e['name'] = mb_substr(trim((string) ($in['name'] ?? '')), 0, 160);
            $e['date'] = trim((string) ($in['date'] ?? ''));
            $e['team'] = $team;
            $e['nom'] = self::named($team) ? ['by' => mb_substr(trim((string) ($in['by'] ?? '')), 0, 120), 'date' => ($e['nom']['date'] ?? '') ?: now()->toDateString()] : [];
            if ($i !== null) $ev[$i] = $e;
            else $ev[] = $e;
            $p['events'] = $ev;
        });
    }

    public function approveEvent(string $hz, int $i, string $by, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($i, $by) {
            $ev = self::events($p);
            abort_unless(isset($ev[$i]), 404);
            abort_unless(!empty($ev[$i]['nom']['date']) && self::named($ev[$i]['team']), 422, 'لا أسماء في الترشيح.');
            $ev[$i]['appr'] = ['by' => $by, 'date' => now()->toDateString()];
            $p['events'] = $ev;
        });
    }

    public function deleteEvent(string $hz, int $i, int $userId): void
    {
        $this->mutate($hz, $userId, function (array &$p) use ($i) {
            $ev = self::events($p);
            abort_unless(isset($ev[$i]), 404);
            array_splice($ev, $i, 1);
            $p['events'] = $ev;
        });
    }
}
