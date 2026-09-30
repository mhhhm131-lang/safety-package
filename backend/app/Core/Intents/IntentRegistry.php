<?php

namespace App\Core\Intents;

use App\Core\Inbox\InboxService;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\ScopeService;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٢ (قرار ٣٥): سجل النوايا الواحد. كل نية: اسم بلغة الناس + شرط + شاشة.
 * الشرط صلاحية من PermissionRegistry، أو دور واجهة، أو مكان في ملف المستخدم، أو ضيف.
 * أزرار المستخدم = النوايا التي يحقق شرطها — الدور الجديد يحصل على أزراره وحده، بلا قائمة مكتوبة له.
 *
 * ٢٦-٨ (قرار ٦٧): محركان فقط — «ما ينتظرك» ما يُراد من الشخص، و«أريد أن» ما يبدؤه بنفسه، فعلاً كان أو قراءة.
 * المجموعات هي المجموعات العشر نفسها في «ما ينتظرك» (InboxService::GROUPS) بترتيبها وأيقوناتها.
 * القاعدة: كل ما أبدؤه له باب واحد. ما له باب في مكان آخر لا يتكرر هنا:
 *   نماذج الفحص، طلب التصريح، الوحدات، ترشيح الفريق ← ملف المكان (البنود ٣، ٨، ٦، ٧) · سجل التصاريح والتقارير ← الرسم ·
 *   الإعدادات ← المزيد · «مكاني» ← مربع المكان · البلاغ ← صفحة الرؤية (٢٦-٢) · الطوارئ ← الزر الأحمر وصفحة المركز (٢٦-٧).
 * ٢٦-٩ (قرار ٦٦): «المزيد» للإعدادات فقط — السجلات التي خرجت منه ولا باب لها في مكان أو رسم تُقرأ من هنا
 *   (المشاريع، الأطراف الخارجية، العمال، النماذج الرقمية)؛ ومن لا مكان في نطاقه يأخذ سجل التصاريح ومخاطر الإدارات هنا.
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
        $active = (bool) $profile?->is_active;
        $placeCode = $profile?->place_id ? Place::find($profile->place_id)?->code : null;
        $folder = $placeCode ? (Place::FOLDERS[$placeCode] ?? null) : null;
        $main = EmergencyBuilding::main();
        // ٢٦-٩: من لا مكان في نطاقه لا مربعات عنده ولا رسم — فما بابه المكان أو الرسم يأخذ زراً هنا حتى لا يُخفى عنه (قرار ٦١)
        $noPlace = false;
        if ($can('permit.list') || $can('risk.list')) { // النطاق يُحسب لمن يعنيه وحده — السجل يُبنى في كل صفحة
            $scope = ScopeService::forUser($user);
            $noPlace = !$scope->isAll() && $scope->places()->isEmpty();
        }
        $G = array_keys(InboxService::GROUPS);
        [$gIncidents, , $gRounds, $gEmergency, $gTeam, $gPermits, $gRisks, $gForms, $gContractors, $gAccounts] = $G;
        $out = collect();
        $add = function (bool $cond, string $key, string $label, ?string $url, string $icon, string $group, bool $primary = false, ?string $hint = null) use (&$out) {
            if ($cond && $url) $out->push(new Intent($key, $label, $url, $icon, $hint, $primary, $group));
        };

        // ── بلاغات الشاغلين ── البلاغ نفسه من صفحة الرؤية (٢٦-٢)؛ هنا المتابعة فقط
        // ٢٦-٨ (قرار ٦٧): «أتابع بلاغاتي» — كان صاحب الحساب لا يجد بلاغاته إلا من الإشعار
        $add($active, 'my_reports', 'أتابع بلاغاتي', route('incidents.mine'), 'bi-search', $gIncidents, false, 'بلاغاتك وحالة كل واحد');

        // ١٩-٧ (قرار ٤٨): نية «العمل اليومي» حُذفت — كل ما كان في اللوحة صار في «ما ينتظرك» و«الأماكن» وملف المكان
        // ٢٥-٣ (قرار ٦٥): «سجل مركز السلامة» دُمج في باب «مركز السلامة وإدارة الطوارئ» (قرار ٥٨: باب واحد) — يفتحه مربع المركز منذ ٢٦-٧
        // ── جولات الفحص ── الفني: نموذج مكانه بضغطة (نماذج الأماكن كلها من ملف كل مكان، البند ٣)
        $add($ui === 'tech' && $folder, 'inspect', 'أفحص مكاني', $folder ? '/'.$folder.'/inspection-form.html' : null, 'bi-clipboard-check', $gRounds, true, $placeCode ? 'نموذج فحص '.$placeCode : null);

        // ── الطوارئ ── (٢٦-٧: الشاشات كلها في صفحة المركز والزر الأحمر؛ ما يلي بعضه يُخفى من القائمة ويبقى في السجل للشريط)
        // ٢٢-٢ (د): حالة مفتوحة تخصّ صاحب الحساب ← شاشته أولاً وقبل كل شيء. لكل حساب، ومنه الموظف بلا صلاحية طوارئ.
        $myCheckIn = $active
            ? \App\Modules\Emergency\Models\EvacuationCheckIn::where('user_id', $user->id)
                ->whereHas('incident', fn ($q) => $q->whereIn('status', ['active', 'contained']))->latest('id')->first()
            : null;
        $add((bool) $myCheckIn, 'my_emergency',
            $myCheckIn?->incident?->is_drill ? 'تمرين إخلاء — ماذا أفعل' : 'حالة طارئة — ماذا أفعل',
            route('emergency.me'), 'bi-exclamation-octagon-fill', $gEmergency, true,
            $myCheckIn ? trim(($myCheckIn->incident->place?->name ?? '').' · أقرب مخرج ونقطة التجمع، وسجّل وصولك بزر') : null);
        // ٢٢-٨ (د): «إخلاء أو إغلاق» كانت نيةً ثانية تفتح الشاشة نفسها — دُمجت في «فعّل»، والوظيفة باقية في الشاشة
        $add($can('emergency.trigger') && $main, 'trigger', 'فعّل حالة طارئة', $main ? route('emergency.buildings.control', $main).($placeCode ? '?place='.$placeCode : '') : null, 'bi-bell-fill', $gEmergency, true, 'الفريق الأولي والقيادة يُنبَّهون فوراً — ومنها الإخلاء والإغلاق الأمني');
        // ٢٢-٣ (د) ثم ٢٥-٣ (قرار ٦٥): «طوارئ الآن» تبقى نيةً لأن الشريط الأحمر الثابت يُبنى منها، وتُخفى من القائمة
        $add($active, 'sos', 'طوارئ الآن', route('emergency.sos'), 'bi-exclamation-octagon-fill', $gEmergency, true, 'تصل مركز السلامة فوراً باسمك ومكانك'); // ٢٦-٣: اسم واحد للزر الأحمر
        $add($can('emergency.respond'), 'arrived', 'وصلتُ إلى الموقع', route('emergency.me'), 'bi-check2-circle', $gEmergency, false, 'يُسجَّل وصولك فيراه المركز');
        $add($can('emergency.drill'), 'drill', 'أجدول تمريناً', route('emergency.drills.create'), 'bi-calendar-event', $gEmergency);
        $add($can('emergency.teams'), 'teams', 'الفريق الأولي', route('emergency.teams.index'), 'bi-people-fill', $gEmergency);
        // ٢٥-٣ (قرار ٦٥، وقرار ٥٨): باب واحد «مركز السلامة وإدارة الطوارئ» — يفتحه مربع المركز منذ ٢٦-٧
        $add($can('emergency.view') || $can('incident.list'), 'center', 'مركز السلامة وإدارة الطوارئ',
            $can('emergency.view') ? route('emergency.dashboard') : route('incidents.index'), 'bi-broadcast', $gEmergency, false,
            $can('emergency.view') ? 'ما يجري الآن، والحالات، وسجل بلاغات الشاغلين' : 'سجل بلاغات الشاغلين');
        $add($can('medical.read'), 'medical', 'الملفات الطبية', route('emergency.medical.dashboard'), 'bi-file-medical', $gEmergency, true, 'للعيادة وحدها، وكل اطّلاع يُسجَّل');
        $add($can('emergency.view') && in_array($role, ['facilities_manager', 'system_admin', 'system_staff'], true), 'systems', 'أنظمة المبنى', route('emergency.iot.dashboard'), 'bi-cpu', $gEmergency);
        // ما يبدؤه الشخص في الطوارئ بنفسه: خطة مكانه (٢٦-١٣ يوجّهها إلى خطتي ملفه)، وملفه الطبي (قرار ٦٠)
        $add(true, 'plans', 'خطة مكاني', $folder ? '/'.$folder.'/index.html' : '/index.html', 'bi-map', $gEmergency);
        $add($active, 'my_medical', 'ملفي الطبي', route('emergency.medical.my-profile'), 'bi-heart-pulse', $gEmergency, false, 'اختياري — لا يطّلع عليه إلا طبيب العيادة');

        // ── الفريق الأولي ── الترشيح من ملف المكان (البند ٧)؛ هنا معرفة الدور والبطاقات
        // ١٩-٦ (قرار ٤٩): بطاقة الشخص مباشرة حين تكون له بطاقة واحدة، وإلا الفهرس
        $card = \App\Modules\Emergency\Support\RoleCards::forUser($user);
        $add(true, 'roles', $card ? 'بطاقة دوري' : 'أعرف دوري', $card ? \App\Modules\Emergency\Support\RoleCards::url($card) : '/role-cards/index.html', 'bi-person-badge', $gTeam, false,
            $card ? \App\Modules\Emergency\Support\RoleCards::CARDS[$card]['name'] : null);
        $add(true, 'roles_map', 'الأدوار والبطاقات', route('app.roles'), 'bi-diagram-2', $gTeam, false, 'من يفعل ماذا: الأدوار الـ٢٧ وبطاقات السلامة الـ٢١ ومن يحملها'); // ٢٠-٦

        // ── التصاريح ── الطلب من ملف المكان (البند ٨) والسجل من الرسم؛ هنا فحص الجاهزية عند البوابة
        $add($can('permit.list') && $noPlace, 'permits_log', 'سجل التصاريح', route('permits.index'), 'bi-file-earmark-check', $gPermits); // لمن له مكان: عمود الرسم
        $add($can('permit.list'), 'gate', 'أتحقق من جاهزية عامل', route('permits.gate'), 'bi-person-check', $gPermits, false, 'قبل دخوله موقع العمل: وثائقه وتدريبه وفحصه الطبي');

        // ── المخاطر ── «السجل العام» قراءة (الكتاب)، و«أفعّل خطراً» فعل من الكتاب إلى الإدارة — سؤالان مختلفان وإن فتحا الصفحة نفسها (قرار ٦٧-٥)
        $add($can('risk.list'), 'book', 'السجل العام للمعهد', route('risk.reference.index'), 'bi-bookmark', $gRisks, false, 'كتاب أخطار المعهد كاملاً');
        $add($can('risk.activate'), 'activate', 'أفعّل خطراً لإدارتي', route('risk.reference.index'), 'bi-lightning-charge', $gRisks, false, 'من كتاب المعهد');
        $add($can('risk.list') && $noPlace, 'risks_log', 'مخاطر الإدارات والأماكن', route('risk.active.index'), 'bi-lightning-charge', $gRisks); // لمن له مكان: ملف المكان، البند ٤
        $add(true, 'hazards', 'أعرف أخطار مكاني', route('hazards.index'), 'bi-book', $gRisks);

        // ── النماذج ──
        $add(true, 'myforms', 'أعبّئ نماذجي', route('forms.mine'), 'bi-inbox', $gForms);
        // ٢٦-٩: السجل خرج من «المزيد» — قراءته سؤال، والإرسال فعل (قرار ٦٧-٥)؛ من يقرأ ولا يرسل (المديرون واللجنة) بابه هذا
        $add($can('form.list'), 'forms_log', 'النماذج الرقمية', route('forms.index'), 'bi-ui-checks', $gForms, false, 'ما أُرسل، ومن عبّأ، والنتائج');
        $add($can('form.send'), 'sendform', 'أرسل نموذجاً لموظفين', route('forms.index'), 'bi-send', $gForms);

        // ── المقاولون ──
        if ($user->isContractor()) {
            $add(true, 'portal', 'بوابتي', route('contractor.home'), 'bi-building', $gContractors, true, 'طرفي ومشاريعي وعمالي ووثائقي');
        }
        // ٢٦-٩ (قرار ٦٦، ولا يُخفى شيء ٦١): السجلات الثلاثة خرجت من «المزيد» — قراءتها باب لكل من يملكها (المديرون واللجنة يقرؤون ولا ينشئون)،
        // والإنشاء زره لمن يملكه؛ السجل قراءة والإنشاء فعل (قرار ٦٧-٥)
        $add($can('project.list'), 'projects_log', 'المشاريع', route('projects.index'), 'bi-list-task', $gContractors, false, 'سجل المشاريع ومقاوليها');
        $add($can('external_party.list'), 'parties_log', 'الأطراف الخارجية', route('external-parties.index'), 'bi-list-ul', $gContractors, false, 'سجل المقاولين والموردين وتأهيلهم');
        $add($can('worker.list'), 'workers_log', 'العمال', route('workers.index'), 'bi-people', $gContractors, false, 'سجل العمال ووثائقهم');
        $add($can('worker.create'), 'worker', 'أسجّل عاملاً', route('workers.create'), 'bi-person-vcard', $gContractors);
        $add($can('project.create'), 'project', 'مشروع جديد', route('projects.create'), 'bi-kanban', $gContractors);
        $add($can('external_party.create'), 'party', 'طرف خارجي جديد', route('external-parties.create'), 'bi-buildings', $gContractors);
        $add($can('competency.view'), 'competency', 'الكفاءات والمهن', route('competency.matrix'), 'bi-award', $gContractors, false, 'ما يلزم كل مهنة من شهادات وتدريب');

        // ── الحسابات ── ٢٠-٤ و٢١-١ (قرار ٥٣): كانا زرين باسمين لشاشة واحدة — صارا «حسابات إدارتي» (٢٦-٨)
        $add($can('system.users.own') && !$can('system.users'), 'my_accounts', 'حسابات إدارتي', route('app.users.index'), 'bi-person-gear', $gAccounts, true,
            $role === 'facilities_manager' ? 'أسجل فنياً بتخصصه وتغطيته — يعمل بعد اعتماد مسؤول السلامة' : 'أرشّح منسق سلامة وحدتي — يعمل بعد اعتماد مسؤول السلامة');

        return $out;
    }
}
