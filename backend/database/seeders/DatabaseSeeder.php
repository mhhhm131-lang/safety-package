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
            AffectedGroupsSeeder::class,
            RiskBookSeeder::class,
            RiskControlsSeeder::class,
            EmergencySeeder::class,
            TradeSeeder::class,          // المرحلة ٦: ٩ مهن من OHSMS (شجرة التصنيف المهني)
            TrainingTopicSeeder::class,  // المرحلة ٦: ٩ مواضيع تدريب مرجعية لكفاءة العمال
            // المرحلة ٦-ب: كتالوج التصاريح وقواعده (بعد المخاطر والمهن — يربط بفئاتها وأسمائها)
            PermitTypesSeeder::class,
            PermitConflictRulesSeeder::class,
            RiskRequiredPermitTypesSeeder::class,
            QualificationChecklistSeeder::class,
        ]);
    }
}
