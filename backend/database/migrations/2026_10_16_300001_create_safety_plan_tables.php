<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣٠-١ (قرار ٨٩ «كلام المكان»): خطة السلامة لكل مكان في مبناه — جدولا الوثيقة `HZ-0x/safety-plan.html`
 * (الاستباقية: المهمة، المسؤول، متى، كيف · التشغيلية: المهمة، المسؤول، التكرار، التوثيق) يُقرآن مرة فيُزرعان صفوفاً،
 * ثم تُعدَّل من الشاشة. الشكل للصنف والكلام للمكان: المهمة لا تُعدَّل، وبقية الخانات كلام المكان.
 * `source` يحفظ ما قالته الوثيقة آخر مرة؛ الصف المعدَّل (`edited_at`) لا تكتبه المزامنة أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->unique()->constrained('places')->cascadeOnDelete();
            $table->string('title', 160);                           // اسم المكان كما في رأس الوثيقة
            $table->unsignedSmallInteger('tasks_count')->default(0);
            $table->unsignedSmallInteger('proactive_count')->default(0);
            $table->unsignedSmallInteger('operational_count')->default(0);
            $table->unsignedSmallInteger('edited_count')->default(0); // صفوف عدّلها المكان أو وسمها «لا ينطبق هنا»
            $table->string('source_path', 255);
            $table->string('fingerprint', 40);                       // sha1 لنص الوثيقة
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        Schema::create('safety_plan_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('safety_plans')->cascadeOnDelete();
            $table->string('layer', 12);                             // proactive | operational
            $table->unsignedSmallInteger('sort');                    // ترتيب الظهور في الوثيقة (١..ن عبر الطبقتين)
            $table->string('task_key', 44);                          // sha1 لنص المهمة (+ ترتيب التكرار إن تكرّرت) — مفتاح الصف عبر المزامنات
            $table->text('task');                                    // المهمة — للصنف، لا تُعدَّل
            $table->string('responsible', 255)->nullable();          // كلام المكان من هنا
            $table->string('when_text', 255)->nullable();            // متى (الاستباقية)
            $table->text('how_text')->nullable();                    // كيف (الاستباقية)
            $table->string('frequency', 255)->nullable();            // التكرار (التشغيلية)
            $table->string('documentation', 255)->nullable();        // التوثيق (التشغيلية)
            $table->json('source');                                  // الخانات الخمس كما في الوثيقة آخر قراءة
            $table->boolean('not_applicable')->default(false);       // «لا ينطبق هنا»
            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edited_by_name', 120)->nullable();
            $table->timestamp('edited_at')->nullable();              // غير فارغ = صف معدَّل لا تمسّه المزامنة
            $table->timestamps();
            $table->unique(['plan_id', 'layer', 'task_key']);
            $table->index(['plan_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_plan_tasks');
        Schema::dropIfExists('safety_plans');
    }
};
