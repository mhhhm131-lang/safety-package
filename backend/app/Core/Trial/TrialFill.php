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
use Illuminate\Support\Facades\Hash;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): تعبئة النظام كما يجب أن يعمل — لا حول فراغ اليوم.
 * لكل وحدة في الهيكل مدير ومنسق سلامة وموظفان؛ الأدوار الـ٢٧ كلها بحساب؛ الفنيون الستة بتغطية المبنى؛
 * لكل مكان فريقه الأولي الأربعة بحساباتهم وخطتاه؛ ولكل وحدة سجل فعلي مفعَّل من الكتاب سُمّي فيه منسقها ومعالجها
 * (فني مختص للخطر الفني، وإداري مختص للإداري). يُستدعى و«وضع التجربة» مشغَّل فيُحذف كله عند الإنهاء.
 *
 * كل اسم دخول يبدأ بـ`tj.`، وكل اسم ظاهر ينتهي بـ«(تجريبي)». الأرقام هنا (عدد الموظفين) تجريبية لا معتمدة.
 */
class TrialFill
{
    public const PREFIX = 'tj.';

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

    /** @return array<string,int> أعداد ما أُنشئ */
    public function run(string $password, ?int $byUserId = null): array
    {
        abort_unless(TrialMode::isOn(), 409, 'التعبئة لا تعمل إلا و«وضع التجربة» مشغَّل.');
        $this->hash = Hash::make($password);
        $this->by = $byUserId ?: (int) UserProfile::where('role', 'system_admin')->where('is_active', true)->orderBy('id')->value('user_id');
        if (!$this->by) $this->by = $this->account('salama', 'system_admin', 'مسؤول السلامة')->id;

        $techs = $this->roles();
        $people = $this->units();
        $this->places();
        $this->teams();
        $this->registers($people, $techs);
        // قرار ٥٦: لا مهل الآن — المتابعة بالتوقيتات (متى وصل، متى استُلم، ماذا عُمل)؛ فالتعبئة لا تضع مهلاً من عندها
        return $this->made;
    }

    // ── الحسابات ──

    private function account(string $name, string $role, string $label, array $profile = [], array $coverage = []): User
    {
        $u = User::create(['username' => self::PREFIX.$name, 'name' => $label.' (تجريبي)', 'email' => self::PREFIX.$name.'@trial.invalid', 'password' => $this->hash]);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'job_title' => $label] + $profile);
        if ($coverage) $p->coverage()->sync($coverage);
        $p->approve(User::find($this->by) ?? $u); // قرار ٥٢: لا يعمل حساب إلا باعتماد مسؤول السلامة
        $this->made['حسابات'] = ($this->made['حسابات'] ?? 0) + 1;
        return $u;
    }

    /** الأدوار التي لا تتبع وحدة بعينها — كل دور من الـ٢٧ بحساب على الأقل. يعيد الفنيين بتخصصهم. */
    private function roles(): array
    {
        $all = Place::orderBy('sort')->pluck('id')->all();
        $adm = OrganizationUnit::where('code', 'adm-eng')->value('id');
        $this->account('munawib', 'system_staff', 'مناوب مركز السلامة', ['place_id' => Place::idByCode('HZ-00')]);
        $this->account('lajna', 'safety_committee', 'عضو لجنة السلامة');
        $this->account('far3', 'branch_manager', 'مدير فرع الرياض', ['organization_unit_id' => $adm]);
        $this->account('qism', 'section_manager', 'مدير قسم الصيانة', ['organization_unit_id' => $adm]);
        $this->account('shuon', 'admin_eng_manager', 'مدير الشؤون الإدارية والهندسية', ['organization_unit_id' => $adm]);
        $this->account('marafiq', 'facilities_manager', 'مدير المرافق والصيانة', ['organization_unit_id' => $adm]);
        $this->account('amn', 'security_safety_head', 'رئيس الأمن والسلامة', ['organization_unit_id' => $adm]);
        foreach ([4 => ['tabib', 'طبيب المعهد'], 5 => ['haris', 'فرد الأمن المكلف'], 13 => ['muraqib', 'مراقب الحريق']] as $card => [$n, $label]) {
            $this->account($n, 'support_team', $label, ['role_card_no' => $card], $all);
        }
        $this->account('maktab', 'consultant_office', 'المكتب الاستشاري');
        $this->account('mushrif', 'contractor_supervisor', 'مشرف المقاول');
        $this->account('muqawil', 'contractor', 'مقاول');
        $this->account('kharij', 'external', 'طرف خارجي');

        $techs = [];
        foreach (PermissionRegistry::TECH_ROLES as $role) {
            $techs[$role] = $this->account(str_replace('tech_', 'fani.', $role), $role, PermissionRegistry::ROLES[$role], ['organization_unit_id' => $adm], $all)->id;
        }
        return $techs;
    }

    /** لكل وحدة: مديرها ومنسق سلامتها وموظفان. «نائب المدير» و«مكتب المدير العام» إدارة عليا. */
    private function units(): array
    {
        $out = [];
        foreach (OrganizationUnit::where('is_active', true)->orderBy('order')->get() as $u) {
            $top = str_starts_with($u->code, 'v-') || $u->code === 'gm';
            $mgr = $this->account($u->code.'.m', $top ? 'top_management' : 'department_manager', ($top ? '' : 'مدير ').$u->name, ['organization_unit_id' => $u->id]);
            $coord = $this->account($u->code.'.c', 'safety_coordinator', 'منسق سلامة '.$u->name, ['organization_unit_id' => $u->id]);
            foreach ([1, 2] as $n) $this->account($u->code.'.e'.$n, 'employee', 'موظف '.$n.' — '.$u->name, ['organization_unit_id' => $u->id]);
            $out[$u->id] = ['unit' => $u, 'manager' => $mgr->id, 'coordinator' => $coord->id];
        }
        return $out;
    }

    // ── الأماكن وفرقها ──

    /** وحدة واحدة على الأقل في كل مكان لا وحدات له؛ والقاعة ٣١٢ (بوابة القبول) في القاعات */
    private function places(): void
    {
        $names = ['duty_room' => 'غرفة المناوبة', 'basement_level' => 'القبو الأول', 'electrical_room' => 'غرفة الكهرباء الرئيسية', 'hvac_unit' => 'وحدة التكييف المركزية',
            'server_hall' => 'قاعة الخوادم', 'restaurant' => 'المطعم الرئيسي', 'training_hall' => '312', 'store' => 'المستودع الرئيسي'];
        foreach (PlaceUnit::TYPES_BY_PLACE as $code => $types) {
            if ($code === PlaceProfile::HUB) continue; // وحدات المكاتب هي الإدارات
            $type = $types[0];
            $pid = Place::idByCode($code);
            if (!$pid || PlaceUnit::where('place_id', $pid)->where('name', $names[$type] ?? $type)->exists()) continue;
            PlaceUnit::create(['place_id' => $pid, 'type' => $type, 'name' => $names[$type] ?? $type, 'floor' => '3', 'capacity' => $type === 'training_hall' ? 30 : null,
                'is_active' => true, 'created_by_id' => $this->by]);
            $this->made['وحدات أماكن'] = ($this->made['وحدات أماكن'] ?? 0) + 1;
        }
    }

    /** فريق أولي معتمد ومحال لكل مكان (ولإدارة واحدة في المكاتب)، بأعضائه الأربعة بحساباتهم، والخطتان */
    private function teams(): void
    {
        $keys = ['evac_coordinator' => 'منسق الإخلاء والطوارئ', 'medic' => 'المسعف', 'rescuer' => 'المنقذ', 'firefighter' => 'الإطفائي'];
        foreach (Place::orderBy('sort')->get() as $place) {
            $hz = $place->code;
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
            $this->made['فرق أولية'] = ($this->made['فرق أولية'] ?? 0) + 1;
        }
    }

    // ── السجل الفعلي ──

    /**
     * لكل وحدة: خطر فعلي من كل صنف في الكتاب (أول خطر معتمد فيه) + التحرش والتنمر OR-03-02،
     * بمنسقها ومعالجها: الفني المختص لصنف فني، والموارد البشرية للعنف والتحرش، ومدير الوحدة لما عداه.
     */
    private function registers(array $people, array $techs): void
    {
        $refs = Risk::where('risk_type', 'reference')->where('status', 'approved')->with('category')->orderBy('id')->get();
        $pick = $refs->groupBy('category_id')->map->first()->values();
        if ($b = $refs->firstWhere('code', 'OR-03-02')) $pick = $pick->reject(fn ($r) => $r->id === $b->id)->push($b);
        $hrUnit = OrganizationUnit::where('code', 'hr')->value('id');
        $hr = $people[$hrUnit]['manager'] ?? null;

        $activate = function (array $row, Risk $ref, ?int $placeId) use ($techs, $hr) {
            $tech = self::TECH_BY_CATEGORY[$ref->category?->name ?? ''] ?? null;
            $handler = $tech ? ($techs[$tech] ?? null) : (str_starts_with((string) $ref->code, 'OR-03') ? ($hr ?: $row['manager']) : $row['manager']);
            $this->risks->activateFromReference($ref, $row['manager'], [
                'scope_type' => 'org_unit', 'organization_unit_id' => $row['unit']->id, 'place_id' => $placeId,
                'assigned_coordinator_id' => $row['coordinator'], 'assigned_field_team_id' => $handler,
                'notes' => 'سجل فعلي تجريبي',
            ]);
            $this->made['أخطار فعلية'] = ($this->made['أخطار فعلية'] ?? 0) + 1;
        };

        foreach ($people as $row) {
            foreach ($pick as $ref) $activate($row, $ref, $row['unit']->place_id);
        }
        // الإدارة المشغّلة لمكان غير المكاتب: أخطاره الفنية في سجلها بمكانه (عطل القاعة ٣١٢ ← سجل عمليات التدريب في HZ-07)
        $byCode = collect($people)->keyBy(fn ($r) => $r['unit']->code);
        foreach (self::OPERATOR as $hz => $code) {
            if (!($row = $byCode[$code] ?? null) || !($pid = Place::idByCode($hz))) continue;
            foreach ($pick->filter(fn ($r) => isset(self::TECH_BY_CATEGORY[$r->category?->name ?? ''])) as $ref) $activate($row, $ref, $pid);
        }
    }
}
