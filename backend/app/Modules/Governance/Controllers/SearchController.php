<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Governance\Models\Place;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentVisibilityService;
use App\Modules\Permit\Models\Permit;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Worker;
use Illuminate\Http\Request;

/**
 * المرحلة ١١-٤ (قرار ٣٤): الباب الثاني — بحث واحد يوزّع على السجلات بحسب صلاحية المستخدم.
 * رمز بلاغ (ش-0012) أو حالة (ط-0003) أو مكان (HZ-06) يفتح سجله مباشرة. لا شاشة تُحذف: كلها تُفتح من هنا أو من مهمة.
 */
class SearchController extends Controller
{
    private const LIMIT = 8;

    public function index(Request $request, IncidentVisibilityService $visibility)
    {
        $q = trim((string) $request->query('q', ''));
        $user = $request->user();
        $role = $user->role();
        $groups = [];
        if ($q === '') return view('governance.search', ['q' => $q, 'groups' => $groups]);

        // اختصارات: الرمز يفتح السجل مباشرة
        $n = strtr($q, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (preg_match('/^ش-\d{3,}$/u', $n) && ($i = Incident::where('code', $n)->first()) && $visibility->canView($i, $user->id)) {
            return redirect()->route('incidents.show', $i);
        }
        if (preg_match('/^ط-\d{3,}$/u', $n) && ($e = EmergencyIncident::where('incident_code', $n)->first()) && PermissionRegistry::hasPermission($role, 'emergency.view')) {
            return redirect()->route('emergency.incidents.live', $e);
        }
        if (preg_match('/^HZ-0\d$/i', $n) && Place::where('code', strtoupper($n))->exists() && PermissionRegistry::uiRole($role)) {
            return redirect('/dashboard.html#place='.strtoupper($n));
        }

        $like = '%'.$q.'%';
        $add = function (string $title, $items, callable $row) use (&$groups) {
            $rows = collect($items)->map($row)->values();
            if ($rows->isNotEmpty()) $groups[] = ['title' => $title, 'rows' => $rows];
        };

        $add('بلاغات الشاغلين', $visibility->getVisibleIncidents($user->id)
            ->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('title', 'like', $like)->orWhere('description', 'like', $like))
            ->latest()->limit(self::LIMIT)->get(),
            fn (Incident $i) => ['label' => $i->code.' — '.$i->title, 'meta' => $i->status_label.($i->place ? ' · '.$i->place->name : ''), 'url' => route('incidents.show', $i)]);

        if (PermissionRegistry::hasPermission($role, 'emergency.view') || PermissionRegistry::hasPermission($role, 'emergency.respond')) {
            $add('الحالات الطارئة', EmergencyIncident::where('incident_code', 'like', $like)->orWhere('description', 'like', $like)->latest('triggered_at')->limit(self::LIMIT)->get(),
                fn (EmergencyIncident $e) => ['label' => $e->incident_code.' — '.$e->getTypeLabel(), 'meta' => $e->getStatusLabel().($e->place ? ' · '.$e->place->name : ''), 'url' => route('emergency.incidents.live', $e)]);
        }
        if (PermissionRegistry::hasPermission($role, 'risk.list')) {
            $add('المخاطر', Risk::where(fn ($w) => $w->where('title', 'like', $like)->orWhere('code', 'like', $like))->orderBy('code')->limit(self::LIMIT)->get(),
                fn (Risk $r) => ['label' => ($r->code ? $r->code.' — ' : '').$r->title, 'meta' => ($r->risk_type === 'active' ? 'خطر فعلي' : 'السجل العام').' · '.(Risk::STATUS_LABELS[$r->status] ?? $r->status), 'url' => route('risk.show', $r)]);
        }
        if (PermissionRegistry::hasPermission($role, 'permit.list')) {
            $add('التصاريح', Permit::where(fn ($w) => $w->where('code', 'like', $like)->orWhere('title', 'like', $like))->latest()->limit(self::LIMIT)->get(),
                fn (Permit $p) => ['label' => $p->code.' — '.$p->title, 'meta' => (Permit::STATUS_LABELS[$p->status] ?? $p->status).($p->place ? ' · '.$p->place->name : ''), 'url' => route('permits.show', $p)]);
        }
        if (PermissionRegistry::hasPermission($role, 'project.list')) {
            $add('المشاريع', Project::where('name', 'like', $like)->latest()->limit(self::LIMIT)->get(),
                fn (Project $p) => ['label' => $p->name, 'meta' => $p->place?->name ?? '', 'url' => route('projects.show', $p)]);
        }
        if (PermissionRegistry::hasPermission($role, 'external_party.list')) {
            $add('الأطراف الخارجية', ExternalParty::where('name', 'like', $like)->orderBy('name')->limit(self::LIMIT)->get(),
                fn (ExternalParty $p) => ['label' => $p->name, 'meta' => $p->party_type ?? '', 'url' => route('external-parties.show', $p)]);
        }
        if (PermissionRegistry::hasPermission($role, 'worker.list')) {
            $add('العمال', Worker::where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('national_id', 'like', $like))->orderBy('full_name')->limit(self::LIMIT)->get(),
                fn (Worker $w) => ['label' => $w->full_name, 'meta' => $w->externalParty?->name ?? '', 'url' => route('workers.show', $w)]);
        }
        if (PermissionRegistry::hasPermission($role, 'form.list')) {
            $add('النماذج الرقمية', FormTemplate::where('title', 'like', $like)->latest()->limit(self::LIMIT)->get(),
                fn (FormTemplate $f) => ['label' => $f->title, 'meta' => FormTemplate::TYPE_LABELS[$f->form_type] ?? $f->form_type, 'url' => route('forms.show', $f)]);
        }
        if (PermissionRegistry::hasPermission($role, 'system.users')) {
            $add('المستخدمون', User::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('username', 'like', $like))->orderBy('name')->limit(self::LIMIT)->get(),
                fn (User $u) => ['label' => $u->name, 'meta' => $u->username.' · '.$u->roleName(), 'url' => route('app.users.edit', $u)]);
        }
        if (PermissionRegistry::uiRole($role)) {
            $add('الأماكن', Place::where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like))->orderBy('sort')->get(),
                fn (Place $p) => ['label' => $p->code.' '.$p->name, 'meta' => 'ملف المكان في اللوحة', 'url' => '/dashboard.html#place='.$p->code]);
        }
        return view('governance.search', ['q' => $q, 'groups' => $groups]);
    }
}
