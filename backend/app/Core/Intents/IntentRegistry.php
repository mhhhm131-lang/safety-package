<?php

namespace App\Core\Intents;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٢ (قرار ٣٥): سجل النوايا الواحد. كل نية: اسم بلغة الناس + شرط + شاشة.
 * الشرط صلاحية من PermissionRegistry، أو دور واجهة، أو مكان في ملف المستخدم، أو ضيف.
 * أزرار المستخدم = النوايا التي يحقق شرطها — الدور الجديد يحصل على أزراره وحده، بلا قائمة مكتوبة له.
 *
 * التعريف: [key, label, icon, group, primary, url|closure(User|null, UserProfile|null): ?string, condition: closure(role, profile, user): bool]
 */
class IntentRegistry
{
    /** الضيف (شاغل أو زائر بلا حساب) */
    public static function guest(): Collection
    {
        return collect([
            new Intent('report', 'أبلّغ عن خطر', route('incident.landing'), 'bi-megaphone-fill', 'بلا تسجيل دخول، يُقيَّد برقم ووقت', true, 'البلاغ'),
            new Intent('track', 'أتابع بلاغي', route('incident.track'), 'bi-search', 'برمز التتبع', false, 'البلاغ'),
            new Intent('hazards', 'أعرف أخطار مكاني', route('hazards.index'), 'bi-book', 'كتاب المعهد: ما هو الخطر وماذا تفعل', false, 'التوعية'),
            new Intent('plans', 'أقرأ خطة مكاني', '/index.html', 'bi-map', 'خطط السلامة والاستجابة للأماكن التسعة', false, 'التوعية'),
            new Intent('roles', 'أعرف دوري', '/role-cards/index.html', 'bi-person-badge', 'بطاقات الأدوار الـ٢١', false, 'التوعية'),
            new Intent('channels', 'قنوات الإبلاغ الأخرى', '/HZ-00-safety-center/reporting-channels.html', 'bi-telephone', null, false, 'البلاغ'),
        ]);
    }

    /** المستخدم بحساب: النوايا مشتقة من صلاحياته وملفه */
    public static function forUser(User $user): Collection
    {
        $role = $user->role();
        $profile = UserProfile::where('user_id', $user->id)->first();
        $can = fn (string $p) => PermissionRegistry::hasPermission($role, $p);
        $ui = PermissionRegistry::uiRole($role);
        $placeCode = $profile?->place_id ? Place::find($profile->place_id)?->code : null;
        $folder = $placeCode ? (Place::FOLDERS[$placeCode] ?? null) : null;
        $main = EmergencyBuilding::main();
        $isTeamMember = EmergencyTeam::active()->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->exists();
        $out = collect();
        $add = function (bool $cond, string $key, string $label, ?string $url, string $icon, string $group, bool $primary = false, ?string $hint = null) use (&$out) {
            if ($cond && $url) $out->push(new Intent($key, $label, $url, $icon, $hint, $primary, $group));
        };

        if ($user->isContractor()) {
            $add(true, 'portal', 'بوابتي', route('contractor.home'), 'bi-building', 'المقاولون', true, 'طرفي ومشاريعي وعمالي ووثائقي');
        }
        // ١٩-٦ (قرار ٤٩): «مكاني» لكل حساب له مكان (ومنهم الموظف) — ملف مكانه بضغطة: فريقه بهواتفهم ورقم المركز وخطتاه
        $my = $profile?->is_active ? $profile->myPlace() : null;
        $add((bool) $my, 'makani', 'مكاني', $my ? route('app.places.units.file', $my, false) : null, 'bi-geo-alt-fill', 'مكاني', true, $my ? $my->name.' — فريقك بهواتفهم ورقم المركز والخطتان' : null);
        // البلاغ
        // قرار المستخدم ٢٠٢٦-٠٩-١٣: زر واحد للجميع يفتح اختيار النوع (عادي/سري/عاجل) بميزة كل نوع له
        $add(true, 'report', 'أبلّغ عن خطر', route('incident.landing'), 'bi-megaphone-fill', 'البلاغ', true, 'عادي لا يُغلق إلا بموافقتك · سري يخفي هويتك · عاجل اتصل بالمركز');
        $add($can('incident.list'), 'incidents', 'سجل مركز السلامة', route('incidents.index'), 'bi-journal-text', 'البلاغ');
        // الفني: مكانه
        $add($ui === 'tech' && $folder, 'inspect', 'أفحص مكاني', $folder ? '/'.$folder.'/inspection-form.html' : null, 'bi-clipboard-check', 'الفحص', true, $placeCode ? 'نموذج فحص '.$placeCode : null);
        $add((bool) $ui, 'forms', 'نماذج الفحص', route('app.inspections'), 'bi-clipboard-check', 'الفحص', $ui !== 'tech', 'النماذج العشرة: آخر جولة وبلاغاتها المفتوحة، وكل نموذج بضغطة');
        // ١٩-٧ (قرار ٤٨): نية «العمل اليومي» حُذفت — كل ما كان في اللوحة صار في «ما ينتظرك» و«الأماكن» وملف المكان
        // الطوارئ
        $add($can('emergency.trigger') && $main, 'trigger', 'فعّل حالة طارئة', $main ? route('emergency.buildings.control', $main).($placeCode ? '?place='.$placeCode : '') : null, 'bi-bell-fill', 'الطوارئ', true, 'الفريق الأولي والقيادة يُنبَّهون فوراً');
        $add($can('emergency.trigger') && $main, 'lockdown', 'إخلاء أو إغلاق', $main ? route('emergency.buildings.control', $main).'#lockdown' : null, 'bi-door-closed', 'الطوارئ');
        $add(($can('emergency.respond') || $isTeamMember) && !$can('emergency.trigger'), 'sos', 'أستغيث الآن', route('emergency.dashboard'), 'bi-exclamation-octagon-fill', 'الطوارئ', true, 'زر الذعر يصل المركز فوراً');
        $add($can('emergency.respond'), 'arrived', 'وصلتُ / أسجّل وصول عضو', route('emergency.dashboard'), 'bi-check2-circle', 'الطوارئ');
        $add($can('emergency.drill'), 'drill', 'أجدول تمريناً', route('emergency.drills.create'), 'bi-calendar-event', 'الطوارئ');
        $add($can('emergency.teams'), 'teams', 'الفريق الأولي', route('emergency.teams.index'), 'bi-people-fill', 'الطوارئ');
        $add($can('emergency.view'), 'emergency', 'مركز الطوارئ', route('emergency.dashboard'), 'bi-broadcast', 'الطوارئ');
        $add($can('emergency.view') && in_array($role, ['facilities_manager', 'system_admin', 'system_staff'], true), 'systems', 'أنظمة المبنى', route('emergency.iot.dashboard'), 'bi-cpu', 'الطوارئ');
        // الإدارة: الفريق والمخاطر
        // ١٩-٥ (قرار ٤٨): الترشيح في ملف مكان الإدارة داخل الخلفية (كان يفتح اللوحة)
        $nomPlace = ($ui === 'dept' && $profile?->organization_unit_id)
            ? (\App\Modules\Governance\Models\OrganizationUnit::find($profile->organization_unit_id)?->place_id ?? Place::idByCode('HZ-06')) : null;
        $add((bool) $nomPlace, 'nominate', 'أرشّح الفريق الأولي لإدارتي', $nomPlace ? route('app.places.units.file', $nomPlace, false).'#pfTeams' : null, 'bi-person-plus', 'إدارتي', true, 'منسق ومسعف ومنقذ وإطفائي من موظفيك');
        // المرحلة ١٨-٣ (قرار ٤٧): وحدات الأماكن — مدير المرافق كل الأماكن، ومدير الإدارة إدارته في مكانها، ومسؤول السلامة الكل
        $unitsUrl = ($ui === 'dept' && $profile?->organization_unit_id && ($ouPlace = \App\Modules\Governance\Models\OrganizationUnit::find($profile->organization_unit_id)?->place_id))
            ? route('app.places.units.index', $ouPlace) : route('app.places.units.hub');
        // ١٩-٤ (قرار ٤٨): «الأماكن» لكل أدوار الواجهة — الفسيفساء وملف كل مكان (ومنه وحداته)؛ ومدير الإدارة يبقى له «موقع إدارتي في مكانها»
        $isCenter = in_array($role, ['system_admin', 'system_staff'], true);
        $add((bool) $ui, 'places', 'الأماكن', route('app.places.units.hub'), 'bi-geo-alt', 'الفحص', false,
            $role === 'facilities_manager' || $isCenter ? 'حالة كل مكان وملفه، ووحداته: القاعات والغرف والمستودعات' : 'حالة كل مكان وملفه: أنظمته وبلاغاته وفريقه وخطتاه');
        $add($ui === 'dept' && !$isCenter && $profile?->organization_unit_id, 'units', 'موقع إدارتي في مكانها', $unitsUrl, 'bi-grid-3x3-gap', 'إدارتي', false, 'الدور والموقع');
        $add($can('risk.activate'), 'activate', 'أفعّل خطراً لإدارتي', route('risk.reference.index'), 'bi-lightning-charge', 'إدارتي', false, 'من كتاب المعهد');
        $add($can('risk.list'), 'book', 'السجل العام للمعهد', route('risk.reference.index'), 'bi-bookmark', 'إدارتي');
        $add($can('form.send'), 'sendform', 'أرسل نموذجاً لموظفين', route('forms.index'), 'bi-send', 'إدارتي');
        $add(true, 'myforms', 'أعبّئ نماذجي', route('forms.mine'), 'bi-inbox', 'النماذج');
        // التصاريح والمقاولون
        $add($can('permit.create') && $can('permit.list'), 'permit', 'أطلب تصريح عمل', route('permits.create'), 'bi-file-earmark-plus', 'التصاريح', false, 'أعمال ساخنة، ارتفاعات، حيز مغلق…');
        $add($can('permit.list'), 'permits', 'سجل التصاريح', route('permits.index'), 'bi-file-earmark-check', 'التصاريح');
        $add($can('worker.create'), 'worker', 'أسجّل عاملاً', route('workers.create'), 'bi-person-vcard', 'المقاولون');
        $add($can('project.create'), 'project', 'مشروع جديد', route('projects.create'), 'bi-kanban', 'المقاولون');
        $add($can('external_party.create'), 'party', 'طرف خارجي جديد', route('external-parties.create'), 'bi-buildings', 'المقاولون');
        // التقارير والتوعية
        $add($can('report.view'), 'reports', 'التقارير', route('reports.dashboard'), 'bi-clipboard-data', 'التقارير');
        $add(true, 'hazards', 'أعرف أخطار مكاني', route('hazards.index'), 'bi-book', 'التوعية');
        $add(true, 'plans', 'خطة مكاني', $folder ? '/'.$folder.'/index.html' : '/index.html', 'bi-map', 'التوعية');
        // ١٩-٦ (قرار ٤٩): بطاقة الشخص مباشرة حين تكون له بطاقة واحدة، وإلا الفهرس
        $card = \App\Modules\Emergency\Support\RoleCards::forUser($user);
        $add(true, 'roles', $card ? 'بطاقة دوري' : 'أعرف دوري', $card ? \App\Modules\Emergency\Support\RoleCards::url($card) : '/role-cards/index.html', 'bi-person-badge', 'التوعية', false,
            $card ? \App\Modules\Emergency\Support\RoleCards::CARDS[$card]['name'] : null);
        $add($can('system.settings'), 'settings', 'الإعدادات', route('app.settings'), 'bi-sliders', 'الإعدادات');
        return $out;
    }
}
