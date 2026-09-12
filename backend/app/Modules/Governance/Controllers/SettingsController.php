<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * المرحلة ١١-٤ (قرار ٣٤): الباب الثالث — «الإعدادات»: ما يُضبط مرة، في صفحة واحدة لمسؤول السلامة.
 * روابط إلى الشاشات القائمة (لا يُحذف منها شيء)، مجمّعة بحسب ما تضبطه.
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->user()->role();
        $can = fn (string $p) => PermissionRegistry::hasPermission($role, $p);
        $groups = [
            ['title' => 'الناس والهيكل', 'items' => array_values(array_filter([
                $can('system.users') ? ['المستخدمون', 'الحسابات والأدوار وكلمات المرور', route('app.users.index')] : null,
                $can('system.org') ? ['الهيكل التنظيمي', 'الإدارات والأقسام وأماكنها', route('app.org.index')] : null,
                $can('emergency.teams') ? ['الفرق', 'الفريق الأولي من ملف المكان والفرق اليدوية', route('emergency.teams.index')] : null,
            ]))],
            ['title' => 'الأماكن والمبنى', 'items' => array_values(array_filter([
                ['الأماكن التسعة', 'الأسماء والرموز ورموز QR للبلاغ', route('app.places.index')],
                ['رموز QR للطباعة', 'ملصق لكل مكان: امسح وأبلغ', route('app.places.qr')],
                $can('emergency.view') ? ['المبنى', 'الطوابق والمخارج ونقاط التجمع', route('emergency.buildings.index')] : null,
                $can('emergency.view') ? ['جهات الاتصال', 'أرقام الطوارئ الرسمية والداخلية', route('emergency.contacts.index')] : null,
                $can('emergency.equipment') ? ['معدات الطوارئ', 'الطفايات والمعدات ودورية فحصها', route('emergency.equipment.index')] : null,
            ]))],
            ['title' => 'المهل والقواعد', 'items' => array_values(array_filter([
                ['مهل بلاغات الشاغلين', 'بلا قيم افتراضية — تُدخلها أنت', route('incidents.settings')],
                $can('emergency.manage') ? ['مهل التصعيد الآلي في الطوارئ', 'أربع قيم بالدقائق', route('emergency.settings')] : null,
                $can('permit.zones') ? ['سعة الأماكن وتعارض التصاريح', 'كم عاملاً في المكان وأي أعمال لا تجتمع', route('permits.settings')] : null,
                $can('competency.manage') ? ['المهن', 'مهن عمال المقاولين', route('competency.trades')] : null,
            ]))],
            ['title' => 'الأنظمة والربط', 'items' => array_values(array_filter([
                $can('integration.manage') ? ['الأجهزة الموصولة', 'لوحة الإنذار والأبواب والمصاعد والتكييف', route('emergency.iot.devices.index')] : null,
                $can('integration.manage') ? ['الكاميرات', 'تسجيل الكاميرات بأماكنها', route('emergency.iot.cameras.dashboard')] : null,
                $can('integration.manage') ? ['قنوات التحقق من المقاولين', 'مصادر التأهيل', route('settings.contractor-channels')] : null,
                ['البريد', 'حال خادم البريد ورسالة اختبار', route('app.mail.index')],
            ]))],
            ['title' => 'النماذج والمحتوى', 'items' => array_values(array_filter([
                $can('form.create') ? ['نموذج رقمي جديد', 'إقرار أو استبيان أو توعية', route('forms.create')] : null,
                $can('form.create') ? ['توليد نموذج من المخاطر', 'من كتاب المعهد', route('forms.generate')] : null,
                $can('risk.list') ? ['السجل العام للمعهد', 'كتاب المخاطر ٨/٤٩/١٧٧', route('risk.reference.index')] : null,
                $can('emergency.view') ? ['خطط الاستجابة', 'مزامَنة من وثائق الأماكن الثمانية', route('emergency.plans.index')] : null,
            ]))],
            ['title' => 'الصيانة', 'items' => array_values(array_filter([
                ['سجل التدقيق', 'من فعل ماذا ومتى', route('app.audit')],
                ['الإغلاق والتسليم', 'النسخة الاحتياطية وتنظيف البيانات التجريبية', route('app.closeout.index')],
            ]))],
        ];
        return view('governance.settings', ['groups' => array_values(array_filter($groups, fn ($g) => $g['items']))]);
    }
}
