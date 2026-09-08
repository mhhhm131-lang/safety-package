<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * البيانات المرجعية التي تُبذر دائماً (آمنة على الإنتاج، لا تكتب فوق تعديلات المستخدم).
 * الحسابات التجريبية منفصلة: DemoUsersSeeder (SEED_DEMO=true فقط).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlacesSeeder::class,
            OrganizationUnitsSeeder::class,
        ]);
    }
}
