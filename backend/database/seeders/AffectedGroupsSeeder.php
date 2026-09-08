<?php

namespace Database\Seeders;

use App\Modules\Risk\Models\AffectedGroup;
use Illuminate\Database\Seeder;

/**
 * الفئات المتأثرة في المعهد (OHSMS لا يبذرها إلا في بيانات العرض التجريبية).
 * من واقع المعهد: موظفون، متدربون وزوار (نصفهم غرباء عن المبنى)، مقاولون، الفريق الأولي، الممتلكات والأنظمة، السمعة.
 */
class AffectedGroupsSeeder extends Seeder
{
    public const GROUPS = [
        ['الموظفون', 'Employees', 'medium'],
        ['المتدربون والزوار', 'Trainees & visitors', 'high'],
        ['المقاولون وعمالهم', 'Contractors & workers', 'high'],
        ['الفريق الأولي للاستجابة', 'Initial response team', 'medium'],
        ['ذوو الإعاقة والحالات الخاصة', 'People with disabilities', 'high'],
        ['الممتلكات والأنظمة', 'Property & systems', 'medium'],
        ['استمرارية الأعمال والسمعة', 'Continuity & reputation', 'medium'],
    ];

    public function run(): void
    {
        foreach (self::GROUPS as [$name, $en, $vuln]) {
            AffectedGroup::firstOrCreate(['name' => $name], ['name_en' => $en, 'vulnerability_level' => $vuln]);
        }
    }
}
