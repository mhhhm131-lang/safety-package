<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٣ — بلاغ الشاغل (Incident من OHSMS) حول نواة المعهد.
 * ترحيل واحد بدل ثمانية في OHSMS. بلا tenant/external_party/project. الحالة نص (لا enum) حتى تطابق آلة الحالة.
 * إضافات المعهد: code، place_id، location_text، بيانات مبلّغ بلا حساب، رمز تتبع لكل بلاغ عام،
 * inspection_ref (الربط العكسي ببلاغ الفحص)، deadline_at/overdue_at (البند ج)، والمرفقات في القاعدة (Render بلا قرص دائم).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->unsignedTinyInteger('escalation_level')->nullable();
            $table->string('incident_type', 10); // normal | urgent | secret
            $table->string('status', 30)->default('new');
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('location_text', 200)->nullable();
            // المبلّغ: بحساب (actor) أو بلا حساب (الشاغل) — السري بلا أي منهما
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name', 120)->nullable();
            $table->string('reporter_phone', 30)->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('incident_coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('incident_field_team_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('ref_received_at')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('field_received_at')->nullable();
            $table->timestamp('in_progress_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('executor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('risk_reference_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->timestamp('coord_verified_at')->nullable();
            $table->foreignId('coord_verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('pending_closure')->default(false);
            $table->boolean('reporter_approved_closure')->default(false);
            $table->timestamp('closure_requested_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->uuid('secret_key')->nullable()->unique();
            $table->string('secret_tracking_code', 20)->nullable()->index();
            // المعهد
            $table->json('inspection_ref')->nullable();      // {key,row} بلاغ الفحص الذي فُتح عليه
            $table->timestamp('deadline_at')->nullable();     // البند ج: المهلة حتى «استلمه الفني»
            $table->timestamp('overdue_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'incident_type']);
            $table->index('place_id');
        });

        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('incident_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('kind', 20)->default('report'); // report (من المبلّغ) | evidence (دليل المعالجة)
            $table->string('original_name', 190)->nullable();
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->longText('data'); // base64 — القرص على Render مؤقت، القاعدة دائمة
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('incident_risks', function (Blueprint $table) {
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->primary(['incident_id', 'risk_id']);
        });

        // إعدادات النظام (البند ج: مهل بلاغ الشاغل بلا قيم افتراضية)
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'incident_risks', 'incident_attachments', 'incident_events', 'incidents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
