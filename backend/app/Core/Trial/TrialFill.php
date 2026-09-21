<?php

namespace App\Core\Trial;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Services\PlaceProfile;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Services\RiskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): تعبئة النظام كما يجب أن يعمل — لا حول فراغ اليوم.
 * لكل وحدة في الهيكل مدير ومنسق سلامة وموظفان؛ الأدوار الـ٢٧ كلها بحساب؛ الفنيون الستة بتغطية المبنى؛
 * لكل مكان فريقه الأولي الأربعة بحساباتهم وخطتاه؛ ولكل وحدة سجل فعلي مفعَّل من الكتاب سُمّي فيه منسقها ومعالجها
 * (فني مختص للخطر الفني، وإداري مختص للإداري). يُستدعى و«وضع التجربة» مشغَّل فيُحذف كله عند الإنهاء.
 *
 * كل اسم دخول يبدأ بـ`tj.`، وكل اسم ظاهر ينتهي بـ«(تجريبي)». الأرقام هنا (عدد الموظفين) تجريبية لا معتمدة.
 *
 * ٢١-٩: التعبئة **تُستأنف** — المنشور يقطع الطلب عند ٦٠ ثانية (`fastcgi_read_timeout`)، فالشاشة تستدعي `next()` مراراً،
 * وكل استدعاء يعمل بميزانية زمنية ثم يحفظ موضعه في `trial_state` (kind = fill). `run()` يكملها في استدعاء واحد لسطر الأوامر والاختبارات.
 */
class TrialFill
{
    public const PREFIX = 'tj.';

    private const STEPS = ['roles', 'units', 'places', 'teams', 'registers', 'operators'];
    private const STEP_LABELS = ['roles' => 'حسابات الأدوار', 'units' => 'مديرو الإدارات ومنسقوها وموظفوها', 'places' => 'وحدات الأماكن',
        'teams' => 'الفرق الأولية', 'registers' => 'السجلات الفعلية للإدارات', 'operators' => 'أخطار الأماكن الفنية'];

    /** الإدارة المشغّلة لكل مكان في التجربة (رمز الوحدة) — اختيار تجريبي لا قرار */
    private const OPERATOR = ['HZ-00' => 'adm-eng', 'HZ-01' => 'adm-eng', 'HZ-02' => 'adm-eng', 'HZ-03' => 'adm-eng', 'HZ-04' => 'it',
        'HZ-05' => 'adm-eng', 'HZ-07' => 'trops', 'HZ-08' => 'proc'];

    /** فروع الكتاب الفنية ← التخصص؛ ما عداها إداري يعالجه مدير الوحدة، والعنف والتحرش تعالجه الموارد البشرية */
    private const TECH_BY_CATEGORY = ['الكهربائية' => 'tech_electrical', 'الحريق والانفجار' => 'tech_fire_alarm', 'الفيزيائية (عوامل البيئة)' => 'tech_hvac',
        'الميكانيكية والإنشائية' => 'tech_elevator'];

    private string $hash = '';
    private int $by = 0;
    /** @var array<string,int> */
    private array $made = [];

    public function __construct(private PlaceProfile $profile, private RiskService $risks) {}

    /** @return array<string,int> أعداد ما أُنشئ — التعبئة كاملة في استدعاء واحد */
    public function run(string $password, ?int $byUserId = null): array
    {
        $this->begin($password, $byUserId);
        do {
            $r = $this->next(PHP_INT_MAX);
        } while (!$r['done']);
        return $r['made'];
    }

    /** يبدأ تعبئة تُستأنف: يحفظ بصمة كلمة المرور ومن يعتمد الحسابات */
    public function begin(string $password, ?int $byUserId = null): void
    {
        abort_unless(TrialMode::isOn(), 409, 'التعبئة لا تعمل إلا و«وضع التجربة» مشغَّل.');
        abort_if(DB::table('trial_state')->where('kind', 'fill')->exists(), 409, 'التعبئة بدأت من قبل.');
        $this->hash = Hash::make($password);
        $this->by = $byUserId ?: (int) UserProfile::where('role', 'system_admin')->where('is_active', true)->orderBy('id')->value('user_id');
        if (!$this->by) $this->by = $this->account('salama', 'system_admin', 'مسؤول السلامة')->id;
        $this->save(0, 0);
    }

    /**
     * يكمل التعبئة من حيث توقفت حتى تنفد الميزانية الزمنية.
     * @return array{done: bool, step: string, progress: string, made: array<string,int>}
     */
    public function next(int $budgetSeconds = 20): array
    {
        abort_unless(TrialMode::isOn(), 409, 'التعبئة لا تعمل إلا و«وضع التجربة» مشغَّل.');
        $state = json_decode((string) DB::table('trial_state')->where('kind', 'fill')->where('name', 'state')->value('value'), true);
        abort_unless(is_array($state), 409, 'لا تعبئة بدأت.');
        [$this->hash, $this->by, $this->made] = [$state['hash'], (int) $state['by'], $state['made'] ?? []];
        [$step, $offset] = [(int) $state['step'], (int) $state['offset']];
        $until = $budgetSeconds === PHP_INT_MAX ? PHP_INT_MAX : microtime(true) + $budgetSeconds;

        while ($step < count(self::STEPS)) {
            $items = $this->items(self::STEPS[$step]);
            while ($offset < count($items)) {
                $this->{'do'.ucfirst(self::STEPS[$step])}($items[$offset]);
                $offset++;
                $this->save($step, $offset);
                if (microtime(true) >= $until) return $this->report($step, $offset, count($items));
            }
            $step++;
            $offset = 0;
            $this->save($step, 0);
        }
        return ['done' => true, 'step' => 'اكتملت', 'progress' => '', 'made' => $this->made];
    }

    /** هل بدأت تعبئة ولم تكتمل؟ — لزر «أكمل التعبئة» في الشاشة */
    public static function pending(): bool
    {
        $state = json_decode((string) DB::table('trial_state')->where('kind', 'fill')->where('name', 'state')->value('value'), true);
        return is_array($state) && (int) ($state['step'] ?? 0) < count(self::STEPS);
    }

    private function save(int $step, int $offset): void
    {
        DB::table('trial_state')->updateOrInsert(['kind' => 'fill', 'name' => 'state'],
            ['value' => json_encode(['hash' => $this->hash, 'by' => $this->by, 'step' => $step, 'offset' => $offset, 'made' => $this->made], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    private function report(int $step, int $offset, int $total): array
    {
        return ['done' => false, 'step' => self::STEP_LABELS[self::STEPS[$step]], 'progress' => "$offset / $total", 'made' => $this->made];
    }

    /** عناصر كل خطوة — قائمة ثابتة الترتيب حتى يصح الاستئناف بالموضع */
    private function items(string $step): array
    {
        return match ($step) {
            'roles' => $this->roleRows(),
            'units', 'registers' => OrganizationUnit::where('is_active', true)->orderBy('order')->orderBy('id')->pluck('id')->all(),
            'places' => array_values(array_filter(array_keys(PlaceUnit::TYPES_BY_PLACE), fn ($c) => $c !== PlaceProfile::HUB)),
            'teams' => Place::orderBy('sort')->pluck('code')->all(),
            'operators' => array_keys(self::OPERATOR),
        };
    }

    private function bump(string $label): void
    {
        $this->made[$label] = ($this->made[$label] ?? 0) + 1;
    }

    // ── الحسابات ──

    private function account(string $name, string $role, string $label, array $profile = [], array $coverage = []): User
    {
        $u = User::create(['username' => self::PREFIX.$name, 'name' => $label.' (تجريبي)', 'email' => self::PREFIX.$name.'@trial.invalid', 'password' => $this->hash]);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'job_title' => $label] + $profile);
        if ($coverage) $p->coverage()->sync($coverage);
        $p->approve(User::find($this->by) ?? $u); // قرار ٥٢: لا يعمل حساب إلا باعتماد مسؤول السلامة
        $this->bump('حسابات');
        return $u;
    }

    private function userId(string $name): ?int
    {
        return User::where('username', self::PREFIX.$name)->value('id');
    }

    /** الأدوار التي لا تتبع وحدة بعينها — كل دور من الـ٢٧ بحساب على الأقل */
    private function roleRows(): array
    {
        $rows = [
            ['munawib', 'system_staff', 'مناوب مركز السلامة', 'place:HZ-00'], ['lajna', 'safety_committee', 'عضو لجنة السلامة', ''],
            ['far3', 'branch_manager', 'مدير فرع الرياض', 'adm'], ['qism', 'section_manager', 'مدير قسم الصيانة', 'adm'],
            ['shuon', 'admin_eng_manager', 'مدير الشؤون الإدارية والهندسية', 'adm'], ['marafiq', 'facilities_manager', 'مدير المرافق والصيانة', 'adm'],
            ['amn', 'security_safety_head', 'رئيس الأمن والسلامة', 'adm'],
            ['tabib', 'support_team', 'طبيب المعهد', 'card:4'], ['haris', 'support_team', 'فرد الأمن المكلف', 'card:5'], ['muraqib', 'support_team', 'مراقب الحريق', 'card:13'],
            ['maktab', 'consultant_office', 'المكتب الاستشاري', ''], ['mushrif', 'contractor_supervisor', 'مشرف المقاول', ''],
            ['muqawil', 'contractor', 'مقاول', ''], ['kharij', 'external', 'طرف خارجي', ''],
        ];
        foreach (PermissionRegistry::TECH_ROLES as $role) $rows[] = [str_replace('tech_', 'fani.', $role), $role, PermissionRegistry::ROLES[$role], 'tech'];
        return $rows;
    }

    private function doRoles(array $row): void
    {
        [$name, $role, $label, $kind] = $row;
        $all = Place::orderBy('sort')->pluck('id')->all();
        $adm = OrganizationUnit::where('code', 'adm-eng')->value('id');
        [$profile, $coverage] = match (true) {
            $kind === 'adm' => [['organization_unit_id' => $adm], []],
            $kind === 'tech' => [['organization_unit_id' => $adm], $all],
            str_starts_with($kind, 'place:') => [['place_id' => Place::idByCode(substr($kind, 6))], []],
            str_starts_with($kind, 'card:') => [['role_card_no' => (int) substr($kind, 5)], $all],
            default => [[], []],
        };
        $this->account($name, $role, $label, $profile, $coverage);
    }

    /** لكل وحدة: مديرها ومنسق سلامتها وموظفان. «نائب المدير» و«مكتب المدير العام» إدارة عليا. */
    private function doUnits(int $unitId): void
    {
        $u = OrganizationUnit::findOrFail($unitId);
        $top = str_starts_with($u->code, 'v-') || $u->code === 'gm';
        $this->account($u->code.'.m', $top ? 'top_management' : 'department_manager', ($top ? '' : 'مدير ').$u->name, ['organization_unit_id' => $u->id]);
        $this->account($u->code.'.c', 'safety_coordinator', 'منسق سلامة '.$u->name, ['organization_unit_id' => $u->id]);
        foreach ([1, 2] as $n) $this->account($u->code.'.e'.$n, 'employee', 'موظف '.$n.' — '.$u->name, ['organization_unit_id' => $u->id]);
    }

    // ── الأماكن وفرقها ──

    /** وحدة واحدة على الأقل في كل مكان لا وحدات له؛ والقاعة ٣١٢ (بوابة القبول) في القاعات */
    private function doPlaces(string $code): void
    {
        $names = ['duty_room' => 'غرفة المناوبة', 'basement_level' => 'القبو الأول', 'electrical_room' => 'غرفة الكهرباء الرئيسية', 'hvac_unit' => 'وحدة التكييف المركزية',
            'server_hall' => 'قاعة الخوادم', 'restaurant' => 'المطعم الرئيسي', 'training_hall' => '312', 'store' => 'المستودع الرئيسي'];
        $type = PlaceUnit::TYPES_BY_PLACE[$code][0];
        $pid = Place::idByCode($code);
        if (!$pid || PlaceUnit::where('place_id', $pid)->where('name', $names[$type] ?? $type)->exists()) return;
        PlaceUnit::create(['place_id' => $pid, 'type' => $type, 'name' => $names[$type] ?? $type, 'floor' => '3', 'capacity' => $type === 'training_hall' ? 30 : null,
            'is_active' => true, 'created_by_id' => $this->by]);
        $this->bump('وحدات أماكن');
    }

    /** فريق أولي معتمد ومحال للمكان (ولإدارة واحدة في المكاتب)، بأعضائه الأربعة بحساباتهم، والخطتان */
    private function doTeams(string $hz): void
    {
        $place = Place::where('code', $hz)->firstOrFail();
        $keys = ['evac_coordinator' => 'منسق الإخلاء والطوارئ', 'medic' => 'المسعف', 'rescuer' => 'المنقذ', 'firefighter' => 'الإطفائي'];
        $dept = $hz === PlaceProfile::HUB ? 'hr' : (self::OPERATOR[$hz] ?? 'adm-eng');
        $un = ['uid' => $hz === PlaceProfile::HUB ? $dept : '_', 'dept' => $dept];
        $team = [];
        foreach ($keys as $role => $label) {
            $acc = $this->account(strtolower(str_replace('-', '', $hz)).'.'.explode('_', $role)[0], $role, $label.' — '.$place->name, ['place_id' => $place->id], [$place->id]);
            $team[] = ['name' => $acc->name, 'user' => $acc->username, 'dept' => $dept, 'phone' => '0500000000', 'trained' => now()->toDateString(), 'trainer' => 'الدفاع المدني (تجريبي)'];
        }
        $this->profile->saveTeam($hz, $un, 0, ['team' => $team, 'nom_by' => 'مدير الإدارة (تجريبي)', 'staff' => 20], $this->by);
        $this->profile->stamp($hz, $un, 0, 'appr', $this->by);
        $this->profile->stamp($hz, $un, 0, 'hr', $this->by);
        $this->profile->savePlans($hz, ['sa' => now()->toDateString(), 'sa_by' => 'مسؤول السلامة (تجريبي)', 'ra' => now()->toDateString(), 'drill' => now()->toDateString()], $this->by);
        $this->bump('فرق أولية');
    }

    // ── السجل الفعلي ──

    /** خطر من كل صنف في الكتاب (أول خطر معتمد فيه) + التحرش والتنمر OR-03-02 */
    private function pick(): \Illuminate\Support\Collection
    {
        $refs = Risk::where('risk_type', 'reference')->where('status', 'approved')->with('category')->orderBy('id')->get();
        $pick = $refs->groupBy('category_id')->map->first()->values();
        if ($b = $refs->firstWhere('code', 'OR-03-02')) $pick = $pick->reject(fn ($r) => $r->id === $b->id)->push($b);
        return $pick;
    }

    /** بمنسق الوحدة ومعالجها: الفني المختص لصنف فني، والموارد البشرية للعنف والتحرش، ومدير الوحدة لما عداه */
    private function activate(OrganizationUnit $unit, Risk $ref, ?int $placeId): void
    {
        $manager = $this->userId($unit->code.'.m');
        $tech = self::TECH_BY_CATEGORY[$ref->category?->name ?? ''] ?? null;
        $handler = $tech ? $this->userId(str_replace('tech_', 'fani.', $tech))
            : (str_starts_with((string) $ref->code, 'OR-03') ? ($this->userId('hr.m') ?: $manager) : $manager);
        $this->risks->activateFromReference($ref, $manager, [
            'scope_type' => 'org_unit', 'organization_unit_id' => $unit->id, 'place_id' => $placeId,
            'assigned_coordinator_id' => $this->userId($unit->code.'.c'), 'assigned_field_team_id' => $handler,
            'notes' => 'سجل فعلي تجريبي',
        ]);
        $this->bump('أخطار فعلية');
    }

    private function doRegisters(int $unitId): void
    {
        $unit = OrganizationUnit::findOrFail($unitId);
        foreach ($this->pick() as $ref) $this->activate($unit, $ref, $unit->place_id);
    }

    /** الإدارة المشغّلة لمكان غير المكاتب: أخطاره الفنية في سجلها بمكانه (عطل القاعة ٣١٢ ← سجل عمليات التدريب في HZ-07) */
    private function doOperators(string $hz): void
    {
        $unit = OrganizationUnit::where('code', self::OPERATOR[$hz])->first();
        $pid = Place::idByCode($hz);
        if (!$unit || !$pid) return;
        foreach ($this->pick()->filter(fn ($r) => isset(self::TECH_BY_CATEGORY[$r->category?->name ?? ''])) as $ref) $this->activate($unit, $ref, $pid);
    }
}
