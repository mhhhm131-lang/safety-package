<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** إدارة الحسابات — لمسؤول السلامة والمناوب (permission:system.users). */
class UsersController extends Controller
{
    /**
     * ٢٠-٤ (قرار ٥١): نطاق الشاشة — «all» لمن يملك system.users (مسؤول السلامة والمناوب)، و«own» لمن يملك system.users.own
     * (مدير المرافق: فنيوه في مبناه فقط، والأدوار التخصصات الستة). غيرهما ٤٠٣.
     */
    private function scope(): string
    {
        $role = auth()->user()->role();
        if (PermissionRegistry::hasPermission($role, 'system.users')) return 'all';
        if (PermissionRegistry::hasPermission($role, 'system.users.own')) return 'own';
        abort(403, 'شاشة الحسابات لمسؤول السلامة والمناوب، و«فنيّي» لمدير المرافق والصيانة، و«منسق سلامة إدارتي» لمدير الوحدة.');
    }

    /** ٢١-١ (قرار ٥٣): نطاق «own» نوعان — مدير المرافق يسجل فنييه في مبناه (٢٠-٤)، ومدير الفرع/الإدارة/القسم يرشّح منسق سلامة وحدته */
    private function ownsTechs(): bool
    {
        return auth()->user()->role() === 'facilities_manager';
    }

    /** الأدوار التي يسجلها صاحب نطاق «own» */
    private function ownRoles(): array
    {
        return $this->ownsTechs() ? PermissionRegistry::TECH_ROLES : ['safety_coordinator'];
    }

    /** وحدة المدير وما تحتها — منسق السلامة يُرشَّح فيها فقط */
    private function ownUnitIds(): array
    {
        $unit = auth()->user()->profile?->organization_unit_id;
        return $unit ? array_values(array_unique(array_map('intval', array_merge([$unit], OrganizationUnit::descendantIdsOf($unit))))) : [];
    }

    /** في نطاق «own» لا يُمس إلا حساب فني في مبنى المدير، أو منسق سلامة في وحدته */
    private function guardTarget(User $user): void
    {
        if ($this->scope() === 'own') {
            $p = $user->profile;
            if ($this->ownsTechs()) {
                abort_unless($p && PermissionRegistry::isTech($p->role) && $p->building_id === auth()->user()->profile?->myBuilding()?->id, 403, 'ليس من فنيّيك.');
            } else {
                abort_unless($p && $p->role === 'safety_coordinator' && in_array((int) $p->organization_unit_id, $this->ownUnitIds(), true), 403, 'ليس منسق سلامة وحدتك.');
            }
        }
    }

    /** ٢٠-٤-ب (قرار ٥٢): من ليس مسؤول السلامة يسجّل ويبقى ما سجّله بانتظار الاعتماد؛ ومسؤول السلامة نافذ فوراً باسمه */
    private function afterWrite(UserProfile $profile, string $note): string
    {
        $actor = auth()->user();
        if (PermissionRegistry::hasPermission($actor->role(), 'system.users.approve')) {
            if ($profile->isPending() || !$profile->approved_at) $profile->approve($actor);
            return '';
        }
        $profile->markPending($actor, $note);
        $name = $profile->user?->name ?? '';
        app(\App\Core\Services\NotificationService::class)->notifyRoles(PermissionRegistry::PERMISSIONS['system.users.approve'], 'account_pending',
            "حساب ينتظر اعتمادك: $name", $note.' — سجّله '.$actor->name, route('app.users.index', ['pending' => 1], false));
        return ' — بانتظار اعتماد مسؤول السلامة، ولا يعمل الحساب حتى يعتمده.';
    }

    public function index(Request $request): View
    {
        $own = $this->scope() === 'own';
        $q = User::with(['profile.organizationUnit', 'profile.place', 'profile.coverage', 'profile.pendingBy'])->orderBy('name');
        if ($own && $this->ownsTechs()) { // ٢٠-٤: «فنيّي» — الفنيون في مبنى المدير
            $b = $request->user()->profile?->myBuilding()?->id;
            $q->whereHas('profile', fn ($w) => $w->whereIn('role', PermissionRegistry::techRoles())->where('building_id', $b));
        } elseif ($own) { // ٢١-١: منسقو سلامة وحدة المدير
            $q->whereHas('profile', fn ($w) => $w->where('role', 'safety_coordinator')->whereIn('organization_unit_id', $this->ownUnitIds()));
        }
        if ($request->boolean('pending')) $q->whereHas('profile', fn ($w) => $w->whereNotNull('pending_since'));
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('username', 'like', "%$s%"));
        }
        if ($r = $request->query('role')) {
            $q->whereHas('profile', fn ($w) => $w->where('role', $r));
        }
        $users = $q->paginate(30)->withQueryString();
        return view('governance.users.index', ['users' => $users, 'roles' => $own ? array_intersect_key(PermissionRegistry::ROLES, array_flip($this->ownRoles())) : PermissionRegistry::ROLES,
            'own' => $own, 'ownTitle' => $own ? ($this->ownsTechs() ? 'فنيّي' : 'منسق سلامة إدارتي') : null, 'canApprove' => PermissionRegistry::hasPermission($request->user()->role(), 'system.users.approve')]);
    }

    public function create(): View
    {
        $this->scope();
        return view('governance.users.form', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        DB::transaction(function () use ($data) {
            $user = User::create(['username' => $data['username'], 'name' => $data['name'], 'email' => $data['email'] ?? null, 'password' => $data['password'], 'external_party_id' => $data['external_party_id'] ?? null]);
            $profile = UserProfile::create([
                'user_id' => $user->id, 'role' => $data['role'],
                'organization_unit_id' => $data['organization_unit_id'] ?? null, 'place_id' => $data['place_id'] ?? null,
                'building_id' => $data['building_id'] ?? null, 'job_title' => $data['job_title'] ?? null, 'role_card_no' => $data['role_card_no'] ?? null, // ٢٠-١/٢٠-٢/٢٠-٦
                'is_active' => true,
            ]);
            $profile->coverage()->sync($data['coverage'] ?? []);
            $this->tail = $this->afterWrite($profile, 'حساب جديد: '.PermissionRegistry::getRoleDisplayName($data['role']));
        });
        return redirect()->route('app.users.index')->with('ok', "أُنشئ الحساب {$data['username']}".$this->tail);
    }

    public function edit(User $user): View
    {
        $this->guardTarget($user);
        return view('governance.users.form', $this->formData($user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($user);
        $data = $this->validated($request, $user);
        // ٢٠-٤-ب: ما يمنح صلاحية (الدور، التغطية، المبنى) يحتاج اعتماداً إن غيّره غير مسؤول السلامة؛ الاسم والمسمى لا
        $old = $user->profile;
        $changed = [];
        if ($old && $old->role !== $data['role']) $changed[] = 'الدور: '.PermissionRegistry::getRoleDisplayName($old->role).' ← '.PermissionRegistry::getRoleDisplayName($data['role']);
        if ($old && array_key_exists('building_id', $data) && $data['building_id'] !== null && (int) $data['building_id'] !== (int) $old->building_id) $changed[] = 'المبنى';
        $newCov = collect($data['coverage'] ?? [])->map(fn ($v) => (int) $v)->sort()->values()->all();
        if ($old && $newCov !== $old->coverage->pluck('id')->sort()->values()->all()) $changed[] = 'التغطية';
        DB::transaction(function () use ($data, $user, $changed) {
            $user->fill(['username' => $data['username'], 'name' => $data['name'], 'email' => $data['email'] ?? null, 'external_party_id' => $data['external_party_id'] ?? null]);
            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $profile = $user->profile ?: new UserProfile(['user_id' => $user->id]);
            $profile->fill(['role' => $data['role'], 'organization_unit_id' => $data['organization_unit_id'] ?? null, 'place_id' => $data['place_id'] ?? null,
                'building_id' => $data['building_id'] ?? $profile->building_id, 'job_title' => $data['job_title'] ?? null, 'role_card_no' => $data['role_card_no'] ?? null]); // ٢٠-١/٢٠-٢/٢٠-٦
            $profile->save();
            $profile->coverage()->sync($data['coverage'] ?? []);
            $this->tail = $changed ? $this->afterWrite($profile, 'تغيير '.implode('، ', $changed)) : '';
        });
        return redirect()->route('app.users.index')->with('ok', "حُدّث الحساب {$user->username}".$this->tail);
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($user);
        if ($user->id === $request->user()->id) {
            return back()->with('err', 'لا تعطّل حسابك أنت');
        }
        $profile = $user->profile;
        $profile->is_active = !$profile->is_active;
        $profile->save();
        return back()->with('ok', $profile->is_active ? "فُعّل {$user->username}" : "عُطّل {$user->username}");
    }

    /** ٢٠-٤-ب: «اعتمد» — يعمل الحساب وتُسجَّل الموافقة باسم مسؤول السلامة وتاريخها */
    public function approve(User $user): RedirectResponse
    {
        $p = $user->profile;
        abort_unless($p && $p->isPending(), 422, 'هذا الحساب لا ينتظر اعتماداً.');
        $p->approve(auth()->user());
        if ($p->pending_by_id) app(\App\Core\Services\NotificationService::class)->create($p->pending_by_id, 'account_approved', "اعتُمد الحساب {$user->name}", 'اعتمده '.auth()->user()->name, route('app.users.index', [], false));
        app('audit.logger')->log(request(), 'approve_account', 'User', $user->id, "اعتماد حساب {$user->username}");
        return back()->with('ok', "اعتُمد الحساب {$user->username} وصار يعمل.");
    }

    /** ٢٠-٤-ب: «أعِده» — يبقى معطَّلاً ويُبلَّغ من سجّله بالسبب */
    public function returnBack(Request $request, User $user): RedirectResponse
    {
        $p = $user->profile;
        abort_unless($p && $p->isPending(), 422, 'هذا الحساب لا ينتظر اعتماداً.');
        $note = $request->validate(['note' => 'nullable|string|max:200'])['note'] ?? null;
        $by = $p->pending_by_id;
        $p->returnBack($note);
        if ($by) app(\App\Core\Services\NotificationService::class)->create($by, 'account_returned', "أُعيد الحساب {$user->name}", $p->return_note, route('app.users.edit', $user, false));
        app('audit.logger')->log(request(), 'return_account', 'User', $user->id, "إعادة حساب {$user->username}: ".$p->return_note);
        return back()->with('ok', "أُعيد الحساب {$user->username} إلى من سجّله.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $this->guardTarget($user);
        $password = Str::password(10, symbols: false);
        $user->password = $password;
        $user->save();
        app('audit.logger')->log(request(), 'reset_password', 'User', $user->id, "إعادة كلمة مرور {$user->username}");
        return back()->with('ok', "كلمة المرور الجديدة لـ {$user->username}: {$password} — تظهر مرة واحدة");
    }

    private string $tail = '';

    private function formData(?User $user): array
    {
        return [
            'user' => $user,
            'own' => $this->scope() === 'own',
            'ownNew' => $this->scope() === 'own' ? ($this->ownsTechs() ? 'فني جديد' : 'منسق سلامة جديد') : null,
            // ٢٠-٣: القابلة للإسناد فقط؛ الدور القديم لحساب قائم يُعرض ليُبدَّل
            'roles' => ($this->scope() === 'own' ? array_intersect_key(PermissionRegistry::ROLES, array_flip($this->ownRoles())) : PermissionRegistry::assignableRoles())
                + (($r = $user?->profile?->role) && in_array($r, PermissionRegistry::LEGACY_ROLES, true) ? [$r => PermissionRegistry::ROLES[$r].' — دور قديم، اختر تخصصاً'] : []),
            'units' => OrganizationUnit::where('is_active', true)->when($this->scope() === 'own' && !$this->ownsTechs(), fn ($q) => $q->whereIn('id', $this->ownUnitIds()))->orderBy('order')->get(),
            'places' => Place::orderBy('sort')->get(),
            'buildings' => \App\Modules\Emergency\Models\EmergencyBuilding::orderBy('id')->get(['id', 'name', 'branch']), // ٢٠-١
            'coverage' => $user?->profile?->coverage->pluck('id')->all() ?? [], // ٢٠-٢
            'multiCards' => array_filter(array_map(fn ($k) => \App\Modules\Emergency\Support\RoleCards::cardsOfRole($k), array_combine(array_keys(PermissionRegistry::ROLES), array_keys(PermissionRegistry::ROLES))), fn ($c) => count($c) > 1), // ٢٠-٦
            'parties' => \App\Modules\Project\Models\ExternalParty::orderBy('name')->get(['id', 'name', 'party_type']),
            'contractorRoles' => \App\Models\User::CONTRACTOR_ROLES,
        ];
    }

    private function validated(Request $request, ?User $user): array
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/i', Rule::unique('users', 'username')->ignore($user?->id)],
            'name' => 'required|string|max:120',
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6', 'max:100'],
            'role' => ['required', Rule::in($this->scope() === 'own' ? $this->ownRoles() : array_keys(PermissionRegistry::assignableRoles()))], // ٢٠-٣: لا يُحفظ دور قديم؛ ٢٠-٤: «فنيّي» تخصصات فقط؛ ٢١-١: مدير الوحدة منسق سلامة فقط
            'organization_unit_id' => $this->scope() === 'own' && !$this->ownsTechs()
                ? ['required', Rule::in($this->ownUnitIds())] // ٢١-١ (قرار ٥٣): منسق السلامة يُرشَّح لوحدة المدير وما تحتها
                : 'nullable|exists:organization_units,id',
            'place_id' => 'nullable|exists:places,id',
            'external_party_id' => 'nullable|exists:external_parties,id', // المرحلة ٦: حساب مقاول/مشرف مقاول/مكتب استشاري → طرفه
            // ٢٠-١/٢٠-٢ (قرار ٥١): المبنى (بلا تحديد = الرئيسي)، المسمى (مؤقت حتى البوابة)، والتغطية من أماكن مبنى الحساب
            'building_id' => 'nullable|exists:emergency_buildings,id',
            'job_title' => 'nullable|string|max:120',
            // ٢٠-٦ (قرار ٥١): الدور ذو البطاقات المتعددة (فريق الإسناد) يحمل حسابُه بطاقته بالاسم
            'role_card_no' => ['nullable', 'integer', Rule::in(\App\Modules\Emergency\Support\RoleCards::cardsOfRole((string) $request->input('role')))],
            'coverage' => 'nullable|array|max:20',
            'coverage.*' => ['integer', Rule::exists('places', 'id')->where(fn ($q) => $q->where('building_id', $request->input('building_id') ?: \App\Modules\Emergency\Models\EmergencyBuilding::main()?->id))],
        ], ['coverage.*.exists' => 'التغطية من أماكن مبنى الحساب فقط.', 'organization_unit_id.in' => 'منسق السلامة يُرشَّح لوحدتك وما تحتها فقط.', 'organization_unit_id.required' => 'اختر الوحدة التي ينسّق سلامتها.']);
        if (!in_array($data['role'], \App\Models\User::CONTRACTOR_ROLES, true)) {
            $data['external_party_id'] = null;
        }
        $data['username'] = Str::lower($data['username']);
        if (count(\App\Modules\Emergency\Support\RoleCards::cardsOfRole($data['role'])) <= 1) $data['role_card_no'] = null; // بطاقة واحدة أو لا شيء: لا حاجة
        return $data;
    }
}
