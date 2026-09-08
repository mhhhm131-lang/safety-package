<?php

namespace Database\Seeders;

use App\Modules\Worker\Models\Trade;
use Illuminate\Database\Seeder;

class TradeSeeder extends Seeder
{
    public function run(): void
    {
        // Major group 7: Craft and Trade Workers
        $major = Trade::firstOrCreate(
            ['code' => '7'],
            ['name' => 'الحرف والمهن', 'name_en' => 'Craft and Trade Workers', 'level' => 'major', 'parent_id' => null]
        );

        // Sub-major 71: Building Workers
        $subMajor71 = Trade::firstOrCreate(
            ['code' => '71'],
            ['name' => 'عمال البناء', 'name_en' => 'Building Workers', 'level' => 'sub_major', 'parent_id' => $major->id]
        );

        // Minor 711: Basic Building Workers
        $minor711 = Trade::firstOrCreate(
            ['code' => '711'],
            ['name' => 'عمال البناء الأساسي', 'name_en' => 'Basic Building Workers', 'level' => 'minor', 'parent_id' => $subMajor71->id]
        );

        // Unit 7111: Mason
        $unit7111 = Trade::firstOrCreate(
            ['code' => '7111'],
            ['name' => 'بنّاء', 'name_en' => 'Mason', 'level' => 'unit', 'parent_id' => $minor711->id]
        );

        // Occupation 71111: General Mason
        Trade::firstOrCreate(
            ['code' => '71111'],
            ['name' => 'بنّاء عام', 'name_en' => 'General Mason', 'level' => 'occupation', 'parent_id' => $unit7111->id]
        );

        // Sub-major 72: Metal Workers
        $subMajor72 = Trade::firstOrCreate(
            ['code' => '72'],
            ['name' => 'عمال المعادن', 'name_en' => 'Metal Workers', 'level' => 'sub_major', 'parent_id' => $major->id]
        );

        // Minor 721: Welders
        $minor721 = Trade::firstOrCreate(
            ['code' => '721'],
            ['name' => 'لحّامون', 'name_en' => 'Welders', 'level' => 'minor', 'parent_id' => $subMajor72->id]
        );

        // Unit 7211: Welder
        $unit7211 = Trade::firstOrCreate(
            ['code' => '7211'],
            ['name' => 'لحّام', 'name_en' => 'Welder', 'level' => 'unit', 'parent_id' => $minor721->id]
        );

        // Occupation 72111: Electric Welder
        Trade::firstOrCreate(
            ['code' => '72111'],
            ['name' => 'لحّام كهربائي', 'name_en' => 'Electric Welder', 'level' => 'occupation', 'parent_id' => $unit7211->id]
        );
    }
}
