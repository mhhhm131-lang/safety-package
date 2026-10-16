<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣١-١ (قرار ٩٠ «المقارنة لكل مبنى»): جدول المقارنة (اللائحة والوضع الحالي) صفٌّ لكل مادة وبند **لكل مبنى**
 * يُزرع من `comparison.html` مرة (١٦ مادة + ٣٣ بنداً تحت المواد ١٢ و١٣ و١٤): العنوان ونص اللائحة وما أعدّه مسؤول السلامة للصنف،
 * والحالة والوضع الحالي والإجراء والمكلّف والموعد والملاحظات كلام المبنى. الجاهزية تُحسب من الصفوف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_compliance_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $t->string('key', 8);                                 // «8» للمادة، «13.4» للبند — مفتاح الصف عبر المزامنات
            $t->unsignedTinyInteger('article_no');
            $t->unsignedTinyInteger('item_no')->nullable();
            $t->unsignedSmallInteger('sort');
            $t->string('title', 255);                             // للصنف
            $t->text('requirement')->nullable();                  // ما تتطلبه اللائحة — للصنف (المادة)
            $t->text('prepared')->nullable();                     // ما أعدّه مسؤول السلامة — للصنف
            $t->string('status', 12)->default('unrated');         // unrated | met | in_progress | not_met | na — كلام المبنى من هنا
            $t->text('current_state')->nullable();                // أصله «الوضع الراهن» في الوثيقة
            $t->text('action')->nullable();
            $t->string('holder_name', 120)->nullable();
            $t->date('due_date')->nullable();
            $t->text('notes')->nullable();
            $t->json('source');                                   // كلام الوثيقة آخر قراءة
            $t->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('edited_by_name', 120)->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->string('source_path', 255);
            $t->string('fingerprint', 40);
            $t->timestamp('synced_at');
            $t->timestamps();
            $t->unique(['building_id', 'key']);
            $t->index(['building_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_compliance_items');
    }
};
