<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١١-١ (ب، قرار ٣٤): فتح الفني المعيَّن للبلاغ = استلامه. وقت أول فتح يُسجَّل هنا
 * فتبقى «فجوة البلاغ» (من الإحالة إلى وصول الفني) مقيسة بلا ضغطة زائدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('field_opened_at')->nullable()->after('field_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $t) => $t->dropColumn('field_opened_at'));
    }
};
