<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣٠-٢ (قرار ٨٩ «كلام المكان»): الخطوة المزروعة من `response-plan.html` تصير قابلة للتعديل في مكانها —
 * مَن، أين، كيف، الهاتف، و«لا ينطبق هنا» — ويبقى متى والنافذة والمسار والعنوان للصنف.
 * `step_key` مفتاح الصف عبر المزامنات (المسار + رقم الخطوة)، و`source` ما قالته الوثيقة آخر قراءة (الكلمات وبطاقات الدور)،
 * والصف المعدَّل (`edited_at`) لا تكتبه المزامنة. القائمة الحية تحمل الهاتف مع الخطوة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('response_plan_steps', function (Blueprint $t) {
            $t->string('step_key', 44)->nullable();
            $t->string('phone_text', 60)->nullable();
            $t->json('source')->nullable();
            $t->boolean('not_applicable')->default(false);
            $t->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('edited_by_name', 120)->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->index(['plan_id', 'step_key']);
        });
        Schema::table('response_plans', function (Blueprint $t) {
            $t->unsignedSmallInteger('edited_count')->default(0);
        });
        Schema::table('emergency_incident_steps', function (Blueprint $t) {
            $t->string('phone_text', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('emergency_incident_steps', fn (Blueprint $t) => $t->dropColumn('phone_text'));
        Schema::table('response_plans', fn (Blueprint $t) => $t->dropColumn('edited_count'));
        Schema::table('response_plan_steps', function (Blueprint $t) {
            $t->dropIndex(['plan_id', 'step_key']);
            $t->dropConstrainedForeignId('edited_by_id');
            $t->dropColumn(['step_key', 'phone_text', 'source', 'not_applicable', 'edited_by_name', 'edited_at']);
        });
    }
};
