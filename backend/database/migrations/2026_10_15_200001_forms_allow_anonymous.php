<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٢ «السرية» (قرار ٨٨ §٤، صندوقان):
 *   `form_templates.allows_anonymous`  القالب يقبل الإجابة السرية: المعبِّئ سري افتراضاً وله «أُعلن اسمي».
 * الصندوقان في الأعمدة القائمة: التكليف يُعلَّم مكتملاً باسم ووقت بلا إجابة؛ والتعبئة السرية بلا
 * `submitted_by_id` ولا `assignment_id` ولا `submitted_ip`، والتاريخ باليوم. لا عمود يربط إجابة بشخص.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_templates', fn (Blueprint $t) => $t->boolean('allows_anonymous')->default(false)->after('fills_as'));
    }

    public function down(): void
    {
        Schema::table('form_templates', fn (Blueprint $t) => $t->dropColumn('allows_anonymous'));
    }
};
