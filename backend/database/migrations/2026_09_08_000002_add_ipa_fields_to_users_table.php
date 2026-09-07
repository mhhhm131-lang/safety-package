<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حسابات المعهد: اسم دخول ودور (مفاتيح الأدوار كما في الواجهة الحالية)
 * ورمز الإدارة لدور مدير الإدارة. تُستبدل بجدول الأدوار الكامل في المرحلة ١.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->unique()->after('id');
            $table->string('role', 32)->after('name');
            $table->string('dept_code', 32)->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('dept_code');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'dept_code', 'is_active']);
        });
    }
};
