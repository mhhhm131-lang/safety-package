<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * الحسابات التجريبية السبعة كما في الواجهة الحالية (كلمة المرور 1234).
 * للتطوير والاختبار فقط. لا تُشغَّل في الإنتاج (انظر BACKEND.md ٧-٤).
 */
class DemoUsersSeeder extends Seeder
{
    public const USERS = [
        ['fani',    'tech',   'الفني المنفّذ'],
        ['marafiq', 'fm',     'مدير المرافق والصيانة'],
        ['shuon',   'adm',    'مدير الشؤون الإدارية والهندسية'],
        ['idara',   'exec',   'الإدارة العليا'],
        ['salama',  'safety', 'مسؤول السلامة'],
        ['maktab',  'cons',   'المكتب الاستشاري'],
        ['mudir',   'dept',   'مدير إدارة'],
    ];

    public function run(): void
    {
        foreach (self::USERS as [$username, $role, $name]) {
            User::updateOrCreate(
                ['username' => $username],
                ['name' => $name, 'role' => $role, 'password' => '1234', 'is_active' => true]
            );
        }
    }
}
