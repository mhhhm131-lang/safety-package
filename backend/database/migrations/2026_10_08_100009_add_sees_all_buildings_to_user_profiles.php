<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بكلمته «نعم» (٢٠٢٦-١٠-٠٨): من يرى كل الفروع = مسؤول السلامة ومن يمنحه خانة «يرى كل الفروع» في حسابه (لا دور جديد).
 * يمنحها مسؤول السلامة وحده (قرار ٥٢: كل ما يمنح صلاحية يعتمده). الفرع لا يرى فرعاً آخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->boolean('sees_all_buildings')->default(false)->after('building_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('sees_all_buildings');
        });
    }
};
