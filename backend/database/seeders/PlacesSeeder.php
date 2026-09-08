<?php

namespace Database\Seeders;

use App\Modules\Governance\Models\Place;
use Illuminate\Database\Seeder;

/** الأماكن التسعة (٨+١) بأسمائها المعتمدة — SOURCE.md §٢. */
class PlacesSeeder extends Seeder
{
    public const PLACES = [
        ['HZ-00', 'مركز السلامة'],
        ['HZ-01', 'القبو ومواقف السيارات'],
        ['HZ-02', 'غرف الكهرباء'],
        ['HZ-03', 'غرف التكييف'],
        ['HZ-04', 'مركز البيانات'],
        ['HZ-05', 'المطاعم'],
        ['HZ-06', 'المكاتب الإدارية'],
        ['HZ-07', 'القاعات التدريبية'],
        ['HZ-08', 'المخازن'],
    ];

    public function run(): void
    {
        foreach (self::PLACES as $i => [$code, $name]) {
            Place::updateOrCreate(['code' => $code], ['name' => $name, 'sort' => $i]);
        }
    }
}
