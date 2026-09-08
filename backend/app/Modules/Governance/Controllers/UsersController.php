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
    public function index(Request $request): View
    {
        $q = User::with(['profile.organizationUnit', 'profile.place'])->orderBy('name');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('username', 'like', "%$s%"));
        }
        if ($r = $request->query('role')) {
            $q->whereHas('profile', fn ($w) => $w->where('role', $r));
        }
        $users = $q->paginate(30)->withQueryString();
        return view('governance.users.index', ['users' => $users, 'roles' => PermissionRegistry::ROLES]);
    }

    public function create(): View
    {
        return view('governance.users.form', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        DB::transaction(function () use ($data) {
            $user = User::create(['username' => $data['username'], 'name' => $data['name'], 'email' => $data['email'] ?? null, 'password' => $data['password'], 'external_party_id' => $data['external_party_id'] ?? null]);
            UserProfile::create([
                'user_id' => $user->id, 'role' => $data['role'],
                'organization_unit_id' => $data['organization_unit_id'] ?? null, 'place_id' => $data['place_id'] ?? null,
                'is_active' => true,
            ]);
        });
        return redirect()->route('app.users.index')->with('ok', "أُنشئ الحساب {$data['username']}");
    }

    public function edit(User $user): View
    {
        return view('governance.users.form', $this->formData($user));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        DB::transaction(function () use ($data, $user) {
            $user->fill(['username' => $data['username'], 'name' => $data['name'], 'email' => $data['email'] ?? null, 'external_party_id' => $data['external_party_id'] ?? null]);
            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $profile = $user->profile ?: new UserProfile(['user_id' => $user->id]);
            $profile->fill(['role' => $data['role'], 'organization_unit_id' => $data['organization_unit_id'] ?? null, 'place_id' => $data['place_id'] ?? null]);
            $profile->save();
        });
        return redirect()->route('app.users.index')->with('ok', "حُدّث الحساب {$user->username}");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('err', 'لا تعطّل حسابك أنت');
        }
        $profile = $user->profile;
        $profile->is_active = !$profile->is_active;
        $profile->save();
        return back()->with('ok', $profile->is_active ? "فُعّل {$user->username}" : "عُطّل {$user->username}");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $password = Str::password(10, symbols: false);
        $user->password = $password;
        $user->save();
        app('audit.logger')->log(request(), 'reset_password', 'User', $user->id, "إعادة كلمة مرور {$user->username}");
        return back()->with('ok', "كلمة المرور الجديدة لـ {$user->username}: {$password} — تظهر مرة واحدة");
    }

    private function formData(?User $user): array
    {
        return [
            'user' => $user,
            'roles' => PermissionRegistry::ROLES,
            'units' => OrganizationUnit::where('is_active', true)->orderBy('order')->get(),
            'places' => Place::orderBy('sort')->get(),
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
            'role' => ['required', Rule::in(array_keys(PermissionRegistry::ROLES))],
            'organization_unit_id' => 'nullable|exists:organization_units,id',
            'place_id' => 'nullable|exists:places,id',
            'external_party_id' => 'nullable|exists:external_parties,id', // المرحلة ٦: حساب مقاول/مشرف مقاول/مكتب استشاري → طرفه
        ]);
        if (!in_array($data['role'], \App\Models\User::CONTRACTOR_ROLES, true)) {
            $data['external_party_id'] = null;
        }
        $data['username'] = Str::lower($data['username']);
        return $data;
    }
}
