<?php

namespace App\Core\Permissions;

/**
 * سجل الأدوار والصلاحيات — المصدر الواحد (BACKEND.md ٤-٣ و٤-٣-ب).
 *
 * المفاتيح الداخلية هي مفاتيح OHSMS نفسها حتى تعمل آلات الحالة المنقولة بلا تعديل،
 * ومقابلها العربي هو أدوار المعهد. أدوار المعهد التي لا مقابل لها في OHSMS مفاتيح جديدة.
 * قرار ٢٠٢٦-٠٩-٠٨: مدير الشؤون الإدارية والهندسية ومدير المرافق والصيانة ورئيس الأمن والسلامة = صلاحيات مدير الإدارة/القسم نفسها (+ أدوار في الطوارئ تُضاف في المرحلة ٤). فريق الإسناد: دوره في الطوارئ (المرحلة ٤)؛ حتى ذلك اطلاع. المكتب الاستشاري: اطلاع.
 */
class PermissionRegistry
{
    public const ROLES = [
        // مقابل OHSMS
        'system_admin'          => 'مسؤول السلامة',
        'system_staff'          => 'مناوب مركز السلامة',
        'top_management'        => 'المدير العام والنواب',
        'safety_committee'      => 'لجنة السلامة',
        'branch_manager'        => 'مدير فرع',
        'department_manager'    => 'مدير إدارة',
        'section_manager'       => 'مدير قسم',
        'safety_coordinator'    => 'منسق السلامة',
        'field_worker'          => 'الفني المنفّذ',
        'contractor_supervisor' => 'مشرف المقاول',
        'contractor'            => 'مقاول',
        'employee'              => 'موظف',
        'external'              => 'طرف خارجي',
        // أدوار المعهد المضافة
        'admin_eng_manager'     => 'مدير الشؤون الإدارية والهندسية',
        'facilities_manager'    => 'مدير المرافق والصيانة',
        'security_safety_head'  => 'رئيس الأمن والسلامة',
        'support_team'          => 'فريق الإسناد',
        'consultant_office'     => 'المكتب الاستشاري',
    ];

    /**
     * دور الواجهة لصفحات المعهد (اللوحة والنماذج العشرة) — مفاتيحها كما في الواجهة الحالية.
     * null = لا وصول للعمل اليومي (الوثائق مفتوحة للجميع أصلاً).
     */
    public const UI_ROLE = [
        'system_admin'          => 'safety',
        'system_staff'          => 'safety',
        'top_management'        => 'exec',
        'safety_committee'      => 'dept',
        'branch_manager'        => 'dept',
        'department_manager'    => 'dept',
        'section_manager'       => 'dept',
        'safety_coordinator'    => 'dept',
        'field_worker'          => 'tech',
        'admin_eng_manager'     => 'adm',
        'facilities_manager'    => 'fm',
        'security_safety_head'  => 'dept',
        'support_team'          => 'dept',
        'consultant_office'     => 'cons',
        'contractor_supervisor' => null,
        'contractor'            => null,
        'employee'              => null,
        'external'              => null,
    ];

    /** الأدوار التي تصل إلى شاشات الوحدات (كل دور له حساب). */
    public const ALL = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'branch_manager',
        'department_manager', 'section_manager', 'safety_coordinator', 'field_worker', 'contractor_supervisor',
        'contractor', 'employee', 'external', 'admin_eng_manager', 'facilities_manager', 'security_safety_head',
        'support_team', 'consultant_office'];

    private const MGMT = ['branch_manager', 'department_manager', 'section_manager'];
    private const INSTITUTE_VIEW = ['admin_eng_manager', 'facilities_manager', 'security_safety_head', 'support_team'];

    public const PERMISSIONS = [
        // النظام والحوكمة
        'system.admin'    => ['system_admin'],
        'system.settings' => ['system_admin'],
        'system.users'    => ['system_admin', 'system_staff'],
        'system.audit'    => ['system_admin'],
        'system.org'      => ['system_admin', 'system_staff'],

        // بلاغ الشاغل (Incident)
        'incident.list'   => ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'branch_manager',
            'department_manager', 'section_manager', 'safety_coordinator', 'contractor_supervisor', 'field_worker',
            'admin_eng_manager', 'facilities_manager', 'security_safety_head', 'support_team'],
        'incident.create' => ['system_admin', 'system_staff', 'safety_coordinator', 'employee', 'contractor_supervisor'],
        'incident.manage' => ['system_admin', 'system_staff', 'safety_coordinator', 'field_worker', 'safety_committee'],

        // المخاطر
        'risk.list'    => ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'branch_manager',
            'department_manager', 'section_manager', 'safety_coordinator',
            'admin_eng_manager', 'facilities_manager', 'security_safety_head', 'support_team'],
        'risk.create'  => ['system_admin', 'system_staff', 'safety_coordinator'],
        // إضافة معهدية: مدير الإدارة/الفرع/القسم يفعّل من السجل العام في سجل وحدته ويسمّي المسؤول (BACKEND.md ٥-٥)
        'risk.activate' => ['system_admin', 'system_staff', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'risk.approve' => ['system_admin', 'system_staff', 'top_management', 'safety_committee'],

        // التصاريح (Hub)
        'permit.list'           => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
            'admin_eng_manager', 'facilities_manager', 'security_safety_head'],
        'permit.create'         => ['system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor', 'employee'],
        'permit.review'         => ['system_admin', 'system_staff', 'safety_coordinator'],
        'permit.safety_approve' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'permit.final_approve'  => ['system_admin', 'system_staff'],
        'permit.activate'       => ['system_admin', 'system_staff', 'safety_coordinator'],
        'permit.edit'           => ['system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor'],
        'permit.cancel'         => ['system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor'],
        'permit.zones'          => ['system_admin', 'system_staff'],

        // النماذج الرقمية
        'form.list'    => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'form.create'  => ['system_admin', 'system_staff', 'safety_coordinator'],
        'form.edit'    => ['system_admin', 'system_staff', 'safety_coordinator'],
        'form.results' => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator'],
        'form.send'    => ['system_admin', 'system_staff', 'safety_coordinator'],
        'form.track'   => ['system_admin', 'system_staff', 'safety_coordinator'],

        // المشاريع والمقاولون والعمال
        'project.list'          => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'project.create'        => ['system_admin', 'system_staff'],
        'project.edit'          => ['system_admin', 'system_staff'],
        'external_party.list'   => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'external_party.create' => ['system_admin', 'system_staff'],
        'external_party.edit'   => ['system_admin', 'system_staff'],
        'external_party.evaluate' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'worker.list'    => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'worker.create'  => ['system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor'],
        'worker.edit'    => ['system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor'],
        'worker.approve' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'worker.manage'  => ['system_admin', 'system_staff', 'safety_coordinator'],
        'competency.view'   => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'competency.manage' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'training.view'       => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'training.manage'     => ['system_admin', 'system_staff', 'safety_coordinator'],
        'training.enroll'     => ['system_admin', 'system_staff', 'safety_coordinator'],
        'training.compliance' => ['system_admin', 'system_staff', 'safety_committee', 'safety_coordinator'],

        // الطوارئ
        'emergency.view'      => ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager',
            'admin_eng_manager', 'facilities_manager', 'security_safety_head', 'support_team'],
        'emergency.manage'    => ['system_admin', 'system_staff', 'safety_coordinator'],
        'emergency.trigger'   => ['system_admin', 'system_staff', 'safety_coordinator', 'branch_manager', 'department_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'emergency.respond'   => ['system_admin', 'system_staff', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'field_worker', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'emergency.drill'     => ['system_admin', 'system_staff', 'safety_coordinator'],
        'emergency.equipment' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'emergency.teams'     => ['system_admin', 'system_staff', 'safety_coordinator'],
        'epc.manage'          => ['system_admin', 'system_staff', 'safety_coordinator'],
        'integration.manage'  => ['system_admin', 'system_staff'],

        // التقارير والتوعية والدعم
        'report.view'      => ['system_admin', 'top_management', 'safety_committee', 'branch_manager', 'department_manager', 'section_manager', 'safety_coordinator', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'awareness.view'   => ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor', 'field_worker', 'employee', 'security_safety_head', 'admin_eng_manager', 'facilities_manager'],
        'awareness.manage' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'support.list'     => self::ALL,
    ];

    public static function hasPermission(string $role, string $permissionCode): bool
    {
        if (!isset(self::PERMISSIONS[$permissionCode])) {
            return false;
        }
        return in_array($role, self::PERMISSIONS[$permissionCode], true);
    }

    public static function getRolePermissions(string $role): array
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $code => $roles) {
            if (in_array($role, $roles, true)) {
                $permissions[] = $code;
            }
        }
        return $permissions;
    }

    public static function getRoleDisplayName(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    /** دور الواجهة لصفحات المعهد، أو null إن لم يكن للدور وصول للعمل اليومي. */
    public static function uiRole(string $role): ?string
    {
        return self::UI_ROLE[$role] ?? null;
    }

    public static function isValidRole(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }
}
