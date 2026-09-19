<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٠-٢ (قرار ٥١): الحساب يحمل صاحبه — المسمى الوظيفي (مؤقت حتى بوابة المعهد؛ ما تعرفه البوابة تملكه البوابة)،
 * والتغطية: الأماكن التي يخدمها الفني أو الأمن أو الطبيب (أكثر من مكان). المبنى أُضيف في ٢٠-١.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->after('role');
        });
        Schema::create('place_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_profile_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_profile_id', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_coverages');
        Schema::table('user_profiles', function (Blueprint $table) { $table->dropColumn('job_title'); });
    }
};
