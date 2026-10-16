<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣٠-٣ (قرار ٨٩ «كلام المكان»): بطاقات الأدوار الـ٢١ صفٌّ لكل بطاقة **لكل مبنى** (مسعف الدمام واحد لأماكنه)،
 * يُزرع من ملفات `role-cards/` والقواعد مرة (الاسم، الوصف، التبعية والسند، المهام الثلاث)، ثم يعدّله المبنى من الشاشة
 * (الاسم، الوصف، المهام، الشاغل وهاتفه، «لا ينطبق هنا»). الرقم والفئة والملف للصنف لا تُعدَّل. خطوات الاستجابة تشير إلى بطاقة المبنى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_role_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $t->unsignedTinyInteger('card_no');                 // ١..٢١ كما في SOURCE.md §٣ — للصنف
            $t->string('category', 20);                         // leadership | support | response-team | occupants — للصنف
            $t->string('name', 160);                            // كلام المبنى من هنا
            $t->string('description', 255)->nullable();
            $t->string('reports_to', 255)->nullable();          // التبعية — كما في الملف
            $t->string('basis', 120)->nullable();               // السند — كما في الملف
            $t->text('tasks_proactive')->nullable();            // سطر لكل مهمة
            $t->text('tasks_operational')->nullable();
            $t->text('tasks_response')->nullable();
            $t->string('holder_name', 120)->nullable();         // شاغل البطاقة في هذا المبنى
            $t->string('holder_phone', 60)->nullable();
            $t->json('source');                                 // ما قاله الملف آخر قراءة
            $t->boolean('not_applicable')->default(false);      // «لا ينطبق هنا» (فرع بلا طبيب)
            $t->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('edited_by_name', 120)->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->string('source_path', 255);
            $t->string('fingerprint', 40);
            $t->timestamp('synced_at');
            $t->timestamps();
            $t->unique(['building_id', 'card_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_role_cards');
    }
};
