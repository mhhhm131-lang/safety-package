<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خطة المعالج — الخطوة ١ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨، `backend/docs/plan-handler-2026-10-07.html`):
 * في السجل العام لكل خطر «الإدارة المعالجة» (يكتبها مسؤول السلامة، وتُحفظ باسمها لتعمل للفروع لاحقاً)
 * و«المعالج» (تخصص من الستة أو شخص؛ يكتبه مدير الإدارة المعالجة من «إدارتي»)، ومن كتبه ومتى.
 * خانتان مرة واحدة للخطر — لا لكل طور. «الجهة والشخص» في الأطوار نص الكتاب يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('handling_unit_id')->nullable()->after('assigned_field_team_id')->constrained('organization_units')->nullOnDelete();
            $table->string('handling_unit_name', 200)->nullable()->after('handling_unit_id');
            $table->string('handler_specialty', 40)->nullable()->after('handling_unit_name');
            $table->foreignId('handler_user_id')->nullable()->after('handler_specialty')->constrained('users')->nullOnDelete();
            $table->foreignId('handler_set_by_id')->nullable()->after('handler_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('handler_set_at')->nullable()->after('handler_set_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handler_set_by_id');
            $table->dropConstrainedForeignId('handler_user_id');
            $table->dropConstrainedForeignId('handling_unit_id');
            $table->dropColumn(['handling_unit_name', 'handler_specialty', 'handler_set_at']);
        });
    }
};
