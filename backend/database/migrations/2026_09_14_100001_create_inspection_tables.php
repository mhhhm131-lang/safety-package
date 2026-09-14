<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١٤ (قرار ٤٢، التصميم ١٤-٢ المعتمد ٢٠٢٦-٠٩-١٤):
 * - inspection_rounds: سجل السلامة = لقطة النموذج نفسه لكل جولة (علاماتها، قراءاتها، حكمها وملخصها، بلاغاتها)،
 *   تُحدَّث ما دامت الجولة قائمة وتُثبَّت حين تبدأ التالية.
 * - inspection_notices: ما أُرسل من إشعارات الفحص — مفتاح فريد لكل حدث فلا يتكرر الإشعار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('form_key', 60);
            $table->string('round_date', 10);
            $table->string('started_at', 10)->default('');
            $table->string('inspector')->nullable();
            $table->string('qualifier')->nullable();
            $table->string('freq', 40)->nullable();
            $table->longText('snapshot');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['form_key', 'round_date', 'started_at']);
        });

        Schema::create('inspection_notices', function (Blueprint $table) {
            $table->id();
            $table->string('notice_key', 191)->unique();
            $table->string('type', 40);
            $table->string('form_key', 60)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_notices');
        Schema::dropIfExists('inspection_rounds');
    }
};
