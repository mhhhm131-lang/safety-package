<?php

namespace App\Core\Permissions;

class PermissionRegistry
{
    public const ROLES = [
        'superuser' => 'مالك النظام',
        'system_admin' => 'مدير النظام',
        'system_staff' => 'موظف النظام',
        'top_management' => 'الإدارة العليا',
        'safety_committee' => 'لجنة السلامة',
        'branch_manager' => 'مدير فرع',
        'department_manager' => 'مدير إدارة',
        'section_manager' => 'مدير قسم',
        'safety_coordinator' => 'منسق سلامة',
        'field_worker' => 'عامل ميداني',
        'contractor_supervisor' => 'مشرف مقاول',
        'contractor' => 'مقاول (بوابة التأهيل)',
        'employee' => 'موظف',
        'external' => 'طرف خارجي',
    ];

    public const PERMISSIONS = [
        // Incidents
        'incident.list' => [
            'system_admin', 'system_staff', 'top_management', 'safety_committee',
            'branch_manager', 'department_manager', 'section_manager',
            'safety_coordinator', 'contractor_supervisor', 'field_worker',
        ],
        'incident.create' => [
            'system_admin', 'system_staff', 'safety_coordinator',
            'employee', 'contractor_supervisor',
        ],
        'incident.manage' => [
            'system_admin', 'system_staff', 'safety_coordinator',
            'field_worker', 'safety_committee',
        ],

        // Risks
        'risk.list' => [
            'system_admin', 'system_staff', 'top_management', 'safety_committee',
            'branch_manager', 'department_manager', 'section_manager',
            'safety_coordinator',
        ],
        'risk.create' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],
        'risk.approve' => [
            'system_admin', 'system_staff', 'top_management', 'safety_committee',
        ],

        // Work Permits
        'work_permit.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'work_permit.create' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'work_permit.approve' => [
            'system_admin', 'system_staff',
        ],
        'work_permit.activate' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Work Permit Requests
        'wpr.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'wpr.create' => [
            'system_admin', 'system_staff', 'safety_coordinator',
            'contractor_supervisor', 'employee',
        ],
        'wpr.review' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],
        'wpr.final_approve' => [
            'system_admin', 'system_staff',
        ],
        'wpr.reject' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Digital Forms
        'form.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager',
        ],
        'form.create' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Reports
        'report.list' => [
            'system_admin', 'top_management', 'safety_committee',
            'branch_manager', 'department_manager', 'section_manager', 'safety_coordinator',
        ],

        // Training
        'training.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager',
        ],
        'training.create' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Projects
        'project.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'project.create' => [
            'system_admin', 'system_staff',
        ],

        // External Parties
        'external_party.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'external_party.create' => [
            'system_admin', 'system_staff',
        ],

        // Workers
        'worker.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'worker.create' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'worker.approve' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // System
        'system.admin' => ['system_admin'],
        'system.settings' => ['system_admin'],
        'system.users' => ['system_admin', 'system_staff'],

        // Support
        'support.list' => [
            'system_admin', 'system_staff', 'top_management', 'safety_committee',
            'branch_manager', 'department_manager', 'section_manager',
            'safety_coordinator', 'field_worker', 'contractor_supervisor',
            'employee', 'external',
        ],

        // Billing
        'billing.view' => ['system_admin'],
        'billing.manage' => ['system_admin'],

        // Training Extended
        'training.view' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager',
        ],
        'training.manage' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'training.compliance' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
        ],
        'training.enroll' => ['system_admin', 'system_staff', 'safety_coordinator'],

        // Competency
        'competency.view' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'competency.manage' => ['system_admin', 'system_staff', 'safety_coordinator'],

        // Smart Permits
        'smart_permit.manage' => ['system_admin', 'system_staff'],

        // Permit Requests
        'permit_request.list' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'contractor_supervisor',
        ],
        'permit_request.create' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor', 'employee',
        ],
        'permit_request.review' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'permit_request.safety_approve' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'permit_request.final_approve' => ['system_admin', 'system_staff'],
        'permit_request.submit' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor', 'employee',
        ],
        'permit_request.edit' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'permit_request.cancel' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],

        // Work Permit Extended
        'work_permit.submit' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'work_permit.edit' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'work_permit.cancel' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Worker Extended
        'worker.edit' => [
            'system_admin', 'system_staff', 'safety_coordinator', 'contractor_supervisor',
        ],
        'worker.manage' => ['system_admin', 'system_staff', 'safety_coordinator'],

        // Reports
        'report.view' => [
            'system_admin', 'top_management', 'safety_committee',
            'branch_manager', 'department_manager', 'section_manager', 'safety_coordinator',
        ],

        // Integration
        'integration.manage' => ['system_admin', 'system_staff'],

        // Wizard
        'wizard.access' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // External Party Extended
        'external_party.edit' => ['system_admin', 'system_staff'],
        'external_party.evaluate' => ['system_admin', 'system_staff', 'safety_coordinator'],

        // Project Extended
        'project.edit' => ['system_admin', 'system_staff'],

        // Form Extended
        'form.edit' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'form.results' => [
            'system_admin', 'system_staff', 'safety_committee', 'safety_coordinator',
        ],
        'form.send' => ['system_admin', 'system_staff', 'safety_coordinator'],
        'form.track' => ['system_admin', 'system_staff', 'safety_coordinator'],

        // EPC
        'epc.manage' => [
            'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Awareness Library
        'awareness.view' => [
            'superuser', 'system_admin', 'system_staff', 'top_management', 'safety_committee',
            'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager',
            'contractor_supervisor', 'field_worker', 'employee',
        ],
        'awareness.manage' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
        ],

        // Emergency & Evacuation
        'emergency.view' => [
            'superuser', 'system_admin', 'system_staff', 'top_management', 'safety_committee',
            'safety_coordinator', 'branch_manager', 'department_manager', 'section_manager',
        ],
        'emergency.manage' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
        ],
        'emergency.trigger' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
            'branch_manager', 'department_manager',
        ],
        'emergency.respond' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
            'branch_manager', 'department_manager', 'section_manager', 'field_worker',
        ],
        'emergency.drill' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
        ],
        'emergency.equipment' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
        ],
        'emergency.teams' => [
            'superuser', 'system_admin', 'system_staff', 'safety_coordinator',
        ],
    ];

    public static function hasPermission(string $role, string $permissionCode): bool
    {
        if (!isset(self::PERMISSIONS[$permissionCode])) {
            return false;
        }

        return in_array($role, self::PERMISSIONS[$permissionCode]);
    }

    public static function getRolePermissions(string $role): array
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $code => $roles) {
            if (in_array($role, $roles)) {
                $permissions[] = $code;
            }
        }
        return $permissions;
    }

    public static function getRoleDisplayName(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }
}
