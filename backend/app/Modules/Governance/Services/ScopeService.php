<?php

namespace App\Modules\Governance\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Collection;

/**
 * المرحلة ٢٠-٥ (قرار ٥١): النطاق يُشتق من الحساب عند الدخول — لا يُسأل أحد شيئاً.
 *   all      المعهد كله: مسؤول السلامة، الإدارة العليا، اللجنة، مدير الشؤون، مدير المرافق، رئيس الأمن
 *   building المناوب (٢٨-٥، س١ بكلمته): أماكن مبنى حسابه
 *   branch   مدير الفرع، ومنسق سلامة الفرع (قرار ٨١: وحدته من نوع «فرع»): أماكن مباني فرعه (المباني التي تشير إلى وحدته أو ما تحتها؛ وإلا بالنص القديم)
 *   unit     مدير الإدارة/القسم (والرئيس التنفيذي بدور مدير إدارة)، منسق إدارة، المكتب: مكان وحدته وما تحتها
 *   coverage الفنيون والإسناد والفريق الأولي: الأماكن التي يغطونها (وإلا مكان الحساب)
 *   self     الموظف والمقاولون والأطراف: مكانه وحده
 * الدور يقول ماذا تفعل (PermissionRegistry)، وهذا يقول أين.
 */
final class ScopeService
{
    private const ALL = ['system_admin', 'top_management', 'safety_committee', 'admin_eng_manager', 'facilities_manager', 'security_safety_head'];
    private const UNIT = ['department_manager', 'section_manager', 'safety_coordinator', 'consultant_office'];
    private const COVERAGE = ['support_team', 'evac_coordinator', 'medic', 'rescuer', 'firefighter'];

    public function __construct(public readonly string $kind, private readonly Collection $places) {}

    /** ٢٨-٣: من يرى المعهد كله — له مبدّل المبنى */
    public static function seesAll(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    /** بكلمته «نعم» (٢٠٢٦-١٠-٠٨): يرى الكل بدوره، أو بخانة «يرى كل الفروع» التي يمنحها مسؤول السلامة في حسابه */
    public static function seesAllFor(?UserProfile $p): bool
    {
        return $p !== null && (self::seesAll($p->role) || (bool) $p->sees_all_buildings);
    }

    /**
     * قرار ٨١ (بكلمته ٢٠٢٦-١٠-١٠): منسق سلامة الفرع يرى فرعه كله «لأنه يشبه دور مسؤول السلامة في فرعه».
     * المعيار بالنوع لا بالاسم: منسق سلامة وحدته من نوع «فرع» (region). منسق وحدته إدارة (الملز أو الفرع) يبقى على وحدته ومكانه.
     * «فرع» حصراً لا «رأس هيكل»: رأس المركز الرئيسي ليس فرعاً، وإلا رأى منسق عليه المعهد كله.
     */
    public static function isBranchCoordinator(?UserProfile $p): bool
    {
        return $p !== null && $p->role === 'safety_coordinator' && $p->organizationUnit?->unit_type === 'region';
    }

    /** ٢٨-٥: مباني فرع مدير الفرع (وقرار ٨١: منسق سلامة الفرع) — التي تشير إلى وحدته أو ما تحتها؛ وإلا بنص الفرع على مبناه؛ وإلا مبناه */
    public static function branchBuildingIds(UserProfile $p): array
    {
        $unitIds = $p->organization_unit_id ? OrganizationUnit::descendantIdsOf($p->organization_unit_id) : [];
        $ids = $unitIds ? EmergencyBuilding::whereIn('branch_unit_id', $unitIds)->pluck('id')->all() : [];
        if ($ids) return $ids;
        $own = $p->myBuilding();
        if (!$own) return [];
        return $own->branch !== null ? EmergencyBuilding::where('branch', $own->branch)->pluck('id')->all() : [$own->id];
    }

    public static function forUser(User $user): self
    {
        $p = $user->profile;
        $role = $user->role();
        if (!$p || !$p->is_active) return new self('self', collect());
        // ٢٨-٢: المكان المعطَّل (صنف لا يوجد في مبناه) خارج كل نطاق
        if (self::seesAllFor($p)) return new self('all', Place::active()->orderBy('building_id')->orderBy('sort')->get());
        // ٢٨-٥ (س١ بكلمته): المناوب بمبناه — مناوب بلا مبنى (لم يُحدَّد) يرى الكل
        if ($role === 'system_staff') {
            $b = $p->myBuilding();
            return $b ? new self('building', Place::active()->where('building_id', $b->id)->orderBy('sort')->get())
                : new self('all', Place::active()->orderBy('building_id')->orderBy('sort')->get());
        }
        // قرار ٨١: منسق سلامة الفرع بنطاق مدير الفرع
        if ($role === 'branch_manager' || self::isBranchCoordinator($p)) {
            return new self('branch', Place::active()->whereIn('building_id', self::branchBuildingIds($p))->orderBy('building_id')->orderBy('sort')->get());
        }
        if (in_array($role, self::UNIT, true)) {
            $ids = $p->organization_unit_id ? OrganizationUnit::descendantIdsOf($p->organization_unit_id) : [];
            $placeIds = OrganizationUnit::whereIn('id', array_merge($ids, [$p->organization_unit_id]))->whereNotNull('place_id')->pluck('place_id');
            if ($p->place_id) $placeIds->push($p->place_id);
            return new self('unit', Place::active()->whereIn('id', $placeIds->unique())->orderBy('sort')->get());
        }
        if (PermissionRegistry::isTech($role) || in_array($role, self::COVERAGE, true)) {
            $cov = $p->coverage()->where('is_active', true)->get();
            if ($cov->isEmpty() && ($mp = $p->myPlace())) $cov = collect([$mp]);
            return new self('coverage', $cov->values());
        }
        $mp = $p->myPlace();
        return new self('self', $mp ? collect([$mp]) : collect());
    }

    /** @return Collection<int, Place> */
    public function places(): Collection { return $this->places; }

    public function codes(): array { return $this->places->pluck('code')->all(); }

    public function contains(string $code): bool { return in_array($code, $this->codes(), true); }

    public function isAll(): bool { return $this->kind === 'all'; }

    /**
     * ٢٠-٥ (ب): من يغطي هذا النظام في هذا المكان؟ فنيون مفعَّلون يغطون المكان (أو مكان حسابهم) وتخصصهم يطابق النظام.
     * @return Collection<int, UserProfile>
     */
    public static function techniciansFor(string $placeCode, string $systemKeyOrRow): Collection
    {
        $pid = Place::idByCode($placeCode);
        if (!$pid) return collect();
        return UserProfile::whereIn('role', PermissionRegistry::techRoles())->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('coverage', fn ($c) => $c->where('places.id', $pid))
                ->orWhere(fn ($w) => $w->where('place_id', $pid)->whereDoesntHave('coverage')))
            ->get()->filter(fn (UserProfile $p) => \App\Modules\Store\Services\SystemSpecialty::fits($p->role, $systemKeyOrRow))->values();
    }
}
