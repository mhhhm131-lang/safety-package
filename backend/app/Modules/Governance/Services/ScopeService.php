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
 *   all      المعهد كله: مسؤول السلامة، المناوب، الإدارة العليا، اللجنة، مدير الشؤون، مدير المرافق، رئيس الأمن
 *   branch   مدير الفرع: أماكن مباني فرعه
 *   unit     مدير الإدارة/القسم (والرئيس التنفيذي بدور مدير إدارة)، منسق السلامة، المكتب: مكان وحدته وما تحتها
 *   coverage الفنيون والإسناد والفريق الأولي: الأماكن التي يغطونها (وإلا مكان الحساب)
 *   self     الموظف والمقاولون والأطراف: مكانه وحده
 * الدور يقول ماذا تفعل (PermissionRegistry)، وهذا يقول أين.
 */
final class ScopeService
{
    private const ALL = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'admin_eng_manager', 'facilities_manager', 'security_safety_head'];
    private const UNIT = ['department_manager', 'section_manager', 'safety_coordinator', 'consultant_office'];
    private const COVERAGE = ['support_team', 'evac_coordinator', 'medic', 'rescuer', 'firefighter'];

    public function __construct(public readonly string $kind, private readonly Collection $places) {}

    public static function forUser(User $user): self
    {
        $p = $user->profile;
        $role = $user->role();
        if (!$p || !$p->is_active) return new self('self', collect());
        if (in_array($role, self::ALL, true)) return new self('all', Place::orderBy('sort')->get());
        if ($role === 'branch_manager') {
            $branch = $p->myBuilding()?->branch;
            $ids = EmergencyBuilding::query()->when($branch !== null, fn ($q) => $q->where('branch', $branch), fn ($q) => $q->whereKey($p->myBuilding()?->id))->pluck('id');
            return new self('branch', Place::whereIn('building_id', $ids)->orderBy('sort')->get());
        }
        if (in_array($role, self::UNIT, true)) {
            $ids = $p->organization_unit_id ? OrganizationUnit::descendantIdsOf($p->organization_unit_id) : [];
            $placeIds = OrganizationUnit::whereIn('id', array_merge($ids, [$p->organization_unit_id]))->whereNotNull('place_id')->pluck('place_id');
            if ($p->place_id) $placeIds->push($p->place_id);
            return new self('unit', Place::whereIn('id', $placeIds->unique())->orderBy('sort')->get());
        }
        if (PermissionRegistry::isTech($role) || in_array($role, self::COVERAGE, true)) {
            $cov = $p->coverage()->get();
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
