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
        // البلاغ
        // قرار المستخدم ٢٠٢٦-٠٩-١٣: زر واحد للجميع يفتح اختيار النوع (عادي/سري/عاجل) بميزة كل نوع له
        $add(true, 'report', 'أبلّغ عن خطر', route('incident.landing'), 'bi-megaphone-fill', 'البلاغ', true, 'عادي لا يُغلق إلا بموافقتك · سري يخفي هويتك · عاجل اتصل بالمركز');
        $add($can('incident.list'), 'incidents', 'سجل البلاغات', route('incidents.index'), 'bi-journal-text', 'البلاغ');
        // الفني: مكانه
        $add($ui === 'tech' && $folder, 'inspect', 'أفحص مكاني', $folder ? '/'.$folder.'/inspection-form.html' : null, 'bi-clipboard-check', 'الفحص', true, $placeCode ? 'نموذج فحص '.$placeCode : null);
        $add((bool) $ui, 'inspections', 'بلاغات الفحص واللوحة', '/dashboard.html', 'bi-speedometer2', 'الفحص');
        // الطوارئ
        $add($can('emergency.trigger') && $main, 'trigger', 'فعّل حالة طارئة', $main ? route('emergency.buildings.control', $main).($placeCode ? '?place='.$placeCode : '') : null, 'bi-bell-fill', 'الطوارئ', true, 'الفريق الأولي والقيادة يُنبَّهون فوراً');
        $add($can('emergency.trigger') && $main, 'lockdown', 'إخلاء أو إغلاق', $main ? route('emergency.buildings.control', $main).'#lockdown' : null, 'bi-door-closed', 'الطوارئ');
        $add(($can('emergency.respond') || $isTeamMember) && !$can('emergency.trigger'), 'sos', 'أستغيث الآن', route('emergency.dashboard'), 'bi-exclamation-octagon-fill', 'الطوارئ', true, 'زر الذعر يصل المركز فوراً');
        $add($can('emergency.respond'), 'arrived', 'وصلتُ / أسجّل وصول عضو', route('emergency.dashboard'), 'bi-check2-circle', 'الطوارئ');
        $add($can('emergency.drill'), 'drill', 'أجدول تمريناً', route('emergency.drills.create'), 'bi-calendar-event', 'الطوارئ');
        $add($can('emergency.teams'), 'teams', 'الفرق الأولية', route('emergency.teams.index'), 'bi-people-fill', 'الطوارئ');
        $add($can('emergency.view'), 'emergency', 'مركز الطوارئ', route('emergency.dashboard'), 'bi-broadcast', 'الطوارئ');
        $add($can('emergency.view') && in_array($role, ['facilities_manager', 'system_admin', 'system_staff'], true), 'systems', 'أنظمة المبنى', route('emergency.iot.dashboard'), 'bi-cpu', 'الطوارئ');
        // الإدارة: الفريق والمخاطر
        $add($ui === 'dept' && $profile?->organization_unit_id, 'nominate', 'أرشّح الفريق الأولي لإدارتي', '/dashboard.html', 'bi-person-plus', 'إدارتي', true, 'منسق ومسعف ومنقذ وإطفائي من موظفيك');
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
        $add(true, 'roles', 'أعرف دوري', '/role-cards/index.html', 'bi-person-badge', 'التوعية');
        $add($can('system.settings'), 'settings', 'الإعدادات', route('app.settings'), 'bi-sliders', 'الإعدادات');
        return $out;
    }
}
