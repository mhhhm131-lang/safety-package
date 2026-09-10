<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١٠-٢ (قرار ٣٠، المكوّن ج): خطوات خطة الاستجابة في الحالة الحية — نسخة من خطوات خطة المكان لحظة التفعيل
 * (المسار الطبي دائماً + مسار المكان بحسب النوع)، مع الحالة ومتى تمت ومن ومقدار الفارق عن المستهدف.
 * تشغيلية: تُحذف مع الحالات (CloseoutService::OPERATIONAL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_incident_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('emergency_incidents')->cascadeOnDelete();
            $table->foreignId('plan_step_id')->nullable()->constrained('response_plan_steps')->nullOnDelete();
            $table->string('path_key', 12);
            $table->string('path_title', 160);
            $table->unsignedSmallInteger('sort');
            $table->string('label', 8);
            $table->string('title', 255);
            $table->string('when_text', 60)->nullable();
            $table->unsignedInteger('window_from_sec')->nullable();
            $table->unsignedInteger('window_to_sec')->nullable();
            $table->boolean('is_conditional')->default(false);
            $table->string('who_text', 255)->nullable();
            $table->string('where_text', 255)->nullable();
            $table->text('how_text')->nullable();
            $table->json('role_cards')->nullable();
            $table->unsignedTinyInteger('role_card_no')->nullable();
            $table->string('status', 10)->default('pending'); // pending|done|skipped
            $table->timestamp('due_at')->nullable();           // triggered_at + window_to_sec (null للشرطية)
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('done_by_name', 120)->nullable();   // من تمت به فعلاً (عضو فريق بلا حساب، أو الفاعل)
            $table->integer('delta_sec')->nullable();          // done - triggered - window_to: موجب = تأخر، سالب = قبل الحد
            $table->string('auto_source', 20)->nullable();     // team_arrived|contained|ended — ما ثبت من السجل آلياً
            $table->timestamp('overdue_alerted_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['incident_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_incident_steps');
    }
};
