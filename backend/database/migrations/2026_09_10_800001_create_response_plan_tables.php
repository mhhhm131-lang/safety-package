<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١٠-١ (BACKEND.md قرار ٣٠، المكوّن أ): خطط الاستجابة الثماني مشتقة من الوثائق `HZ-0x/response-plan.html`.
 * الوثيقة هي الحقيقة؛ الجدولان نسخة مقروءة منها بلا كتابة عكسية. المكان الواحد له خطة واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->unique()->constrained('places')->cascadeOnDelete();
            $table->string('title', 120);                       // اسم المكان كما في رأس الوثيقة
            $table->unsignedSmallInteger('declared_total')->nullable(); // «N خطوة» في رأس الوثيقة كما هو (لا يُحسب)
            $table->unsignedSmallInteger('steps_count')->default(0);    // خطوات المسارات (الطبي + الخاص + حالات أخرى)
            $table->unsignedSmallInteger('detection_count')->default(0); // بنود الكشف والبلاغ (٠)
            $table->unsignedSmallInteger('scenario_count')->default(0);  // سيناريوهات أ/ب (الكهرباء، التكييف، مركز البيانات)
            $table->unsignedSmallInteger('no_card_count')->default(0);   // خطوات مسار بلا بطاقة دور مطابقة
            $table->string('source_path', 255);
            $table->string('fingerprint', 40);                  // sha1 لنص الوثيقة
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        Schema::create('response_plan_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('response_plans')->cascadeOnDelete();
            $table->string('path_key', 12);                     // detection|scenario|medical|fire|other
            $table->string('path_title', 160);
            $table->unsignedSmallInteger('path_declared_count')->nullable(); // «٣ خطوات» في رأس المسار
            $table->unsignedSmallInteger('sort');               // ترتيب الظهور في الوثيقة (١..ن)
            $table->string('label', 8);                         // رقم الخطوة كما في الوثيقة: ١ · أ · ① · ←
            $table->string('title', 255);
            $table->string('when_text', 60)->nullable();        // «الثانية الأولى»، «٥–١٥ دقيقة»، «عند عدم السيطرة»
            $table->unsignedInteger('window_from_sec')->nullable();
            $table->unsignedInteger('window_to_sec')->nullable();
            $table->boolean('is_conditional')->default(false);  // شرطية بلا نافذة زمنية
            $table->string('who_text', 255)->nullable();
            $table->string('where_text', 255)->nullable();
            $table->text('how_text')->nullable();
            $table->json('role_cards')->nullable();             // أرقام البطاقات المطابقة بترتيب ورودها في «من»
            $table->unsignedTinyInteger('role_card_no')->nullable(); // البطاقة الأولى (صاحب الخطوة)
            $table->timestamps();
            $table->index(['plan_id', 'path_key', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_plan_steps');
        Schema::dropIfExists('response_plans');
    }
};
