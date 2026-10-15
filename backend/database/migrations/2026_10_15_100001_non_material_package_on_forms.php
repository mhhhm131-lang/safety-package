<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٣ (قرار ٨٧، وثيقة التصميم §٤-١): حزمة الطبقة الثانية في وحدة النماذج.
 *   `form_templates.package_code`  رمز القالب في الحزمة (٠١…٠٨، وac k للإقرار السري المولَّد) — به تُعرف وتُبذر مرة واحدة.
 *   `form_templates.fills_as`      `unit` = يعبّئه مدير الوحدة عن إدارته (الثمانية كلها بكلمته)، `user` = كل مكلَّف (القوالب القائمة).
 *   `form_fields.feeds_ack`        الحقل يدخل في توليد الإقرار السري (٢٩-٥): آليات ٠١ وبيئته الداخلية، وما وُسم في ٠٢ و٠٤.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_templates', function (Blueprint $t) {
            $t->string('package_code', 8)->nullable()->unique()->after('form_type');
            $t->string('fills_as', 8)->default('user')->after('package_code');
        });
        Schema::table('form_fields', function (Blueprint $t) {
            $t->boolean('feeds_ack')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', fn (Blueprint $t) => $t->dropColumn('feeds_ack'));
        Schema::table('form_templates', function (Blueprint $t) {
            $t->dropUnique(['package_code']);
            $t->dropColumn(['package_code', 'fills_as']);
        });
    }
};
