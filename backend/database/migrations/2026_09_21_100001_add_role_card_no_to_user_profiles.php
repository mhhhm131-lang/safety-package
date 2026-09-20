<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٠-٦ (قرار ٥١): مطابقة الأدوار بالبطاقات — الدور الذي له أكثر من بطاقة سلامة (فريق الإسناد: الطبيب ٤، الأمن ٥،
 * مراقب الحريق ١٣) يحمل حسابُه بطاقته بالاسم. البطاقة مهمة لا دور؛ الأدوار ذات البطاقة الواحدة لا تحتاج هذا الحقل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('role_card_no')->nullable()->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) { $table->dropColumn('role_card_no'); });
    }
};
