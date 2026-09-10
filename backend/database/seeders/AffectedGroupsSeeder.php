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
        ['استمرارية الأعمال', 'Business continuity', 'medium'],
        ['السمعة', 'Reputation', 'medium'],
        ['الخسائر المالية والقانونية', 'Financial & legal losses', 'medium'],
    ];

    /** الاسم القديم المدمج (قبل قرار ٢٤) يُعاد تسميته بدل حذفه حتى تبقى تفاصيل المخاطر المرتبطة به. */
    private const RENAMES = ['استمرارية الأعمال والسمعة' => 'استمرارية الأعمال'];

    public function run(): void
    {
        foreach (self::RENAMES as $old => $new) {
            if (($g = AffectedGroup::where('name', $old)->first()) && !AffectedGroup::where('name', $new)->exists()) {
                $g->update(['name' => $new, 'name_en' => 'Business continuity']);
            }
        }
        foreach (self::GROUPS as [$name, $en, $vuln]) {
            AffectedGroup::firstOrCreate(['name' => $name], ['name_en' => $en, 'vulnerability_level' => $vuln]);
        }
    }
}
