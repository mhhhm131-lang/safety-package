<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Database\Seeder;

/**
 * الحسابات التجريبية السبعة كما في الواجهة الحالية (كلمة المرور 1234).
 * للتطوير والاختبار فقط. تُعطَّل في الإنتاج عند قبول المرحلة ١ (SEED_DEMO=false).
 */
class DemoUsersSeeder extends Seeder
{
    public const USERS = [
        ['fani',    'field_worker',       'الفني المنفّذ'],
        ['marafiq', 'facilities_manager', 'مدير المرافق والصيانة'],
        ['shuon',   'admin_eng_manager',  'مدير الشؤون الإدارية والهندسية'],
        ['idara',   'top_management',     'الإدارة العليا'],
        ['salama',  'system_admin',       'مسؤول السلامة'],
        ['maktab',  'consultant_office',  'المكتب الاستشاري'],
        ['mudir',   'department_manager', 'مدير إدارة'],
    ];

    public function run(): void
    {
        foreach (self::USERS as [$username, $role, $name]) {
            $user = User::updateOrCreate(['username' => $username], ['name' => $name, 'password' => '1234']);
            UserProfile::updateOrCreate(['user_id' => $user->id], ['role' => $role, 'is_active' => true]);
        }
    }
}
