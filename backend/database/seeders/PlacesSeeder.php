<?php

namespace Database\Seeders;

use App\Modules\Governance\Models\Place;
use Illuminate\Database\Seeder;

/** أماكن الملز التسعة (٨+١) بأسمائها المعتمدة — SOURCE.md §٢. ٢٨-١: صنف كل مكان رمزه؛ أماكن الفروع تُنشأ من شاشة المباني (٢٨-٢). */
class PlacesSeeder extends Seeder
{
    /** ٢٨-٢: الأصناف مصدرها الواحد `Place::CATEGORIES` */
    public const PLACES = Place::CATEGORIES;

    public function run(): void
    {
        foreach (self::PLACES as $i => [$code, $name]) {
            Place::updateOrCreate(['code' => $code], ['name' => $name, 'sort' => $i, 'category' => $code]);
        }
    }
}
