<?php

namespace Database\Seeders;

use App\Modules\Worker\Models\TrainingTopic;
use Illuminate\Database\Seeder;

class TrainingTopicSeeder extends Seeder
{
    public function run(): void
    {
        $topics = [
            [
                'code'            => 'IND01',
                'name'            => 'التعريف بالموقع',
                'name_en'         => 'Site Induction',
                'category'        => 'induction',
                'duration_hours'  => 2,
                'validity_months' => 0,
            ],
            [
                'code'            => 'PC01',
                'name'            => 'التحكم في التصاريح',
                'name_en'         => 'Permit Control',
                'category'        => 'permit_control',
                'duration_hours'  => 4,
                'validity_months' => 12,
            ],
            [
                'code'            => 'HR01',
                'name'            => 'العمل على ارتفاع',
                'name_en'         => 'Working at Height',
                'category'        => 'high_risk',
                'duration_hours'  => 8,
                'validity_months' => 12,
            ],
            [
                'code'            => 'HR02',
                'name'            => 'الأماكن المحصورة',
                'name_en'         => 'Confined Spaces',
                'category'        => 'high_risk',
                'duration_hours'  => 8,
                'validity_months' => 12,
            ],
            [
                'code'            => 'HR03',
                'name'            => 'الأعمال الساخنة',
                'name_en'         => 'Hot Works',
                'category'        => 'high_risk',
                'duration_hours'  => 4,
                'validity_months' => 12,
            ],
            [
                'code'            => 'EM01',
                'name'            => 'الإخلاء والطوارئ',
                'name_en'         => 'Emergency Evacuation',
                'category'        => 'emergency',
                'duration_hours'  => 2,
                'validity_months' => 6,
            ],
            [
                'code'            => 'LD01',
                'name'            => 'قيادة السلامة',
                'name_en'         => 'Safety Leadership',
                'category'        => 'leadership',
                'duration_hours'  => 8,
                'validity_months' => 24,
            ],
            [
                'code'            => 'ENV01',
                'name'            => 'الوعي البيئي',
                'name_en'         => 'Environmental Awareness',
                'category'        => 'environmental',
                'duration_hours'  => 4,
                'validity_months' => 12,
            ],
        ];

        foreach ($topics as $topic) {
            TrainingTopic::firstOrCreate(
                ['code' => $topic['code']],
                $topic
            );
        }
    }
}
