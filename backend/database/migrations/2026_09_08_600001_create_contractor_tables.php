<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٦ — المشاريع والمقاولون والعمال (Modules/Project + Modules/Worker من OHSMS، BACKEND.md ٥-٧).
 * ترحيل واحد لـ ٢١ جدولاً (كانت ٢٧ ترحيلاً) بلا tenant_id ولا الأنشطة الاقتصادية ولا حدود الخطة؛ enum → نص.
 * إضافات المعهد: projects.place_id (المكان الذي يُنفَّذ فيه)، workers.place_id بدل `area` النصي،
 * users.external_party_id (حساب المقاول يرى بيانات طرفه فقط)، incidents.external_party_id/project_id (الفحص ٧ في التأهيل)،
 * risks.project_id/external_party_id (سجل مخاطر المشروع/المقاول كما في OHSMS)، والمستندات base64 في القاعدة (قرص Render مؤقت).
 * training_topics جدول مرجعي لكفاءة العمال (وحدة التدريب لموظفي المعهد خارج النطاق §٢-٤).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── الأطراف الخارجية والمشاريع ──
        Schema::create('external_parties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('party_type', 20); // contractor|service_provider|supplier|consultant
            $table->string('contact_person', 200)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('cr_number', 50)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('registration_url', 500)->nullable();
            $table->string('status', 10)->default('active'); // active|inactive|blocked|pending
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['party_type', 'status']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: مكان التنفيذ (إلزامي في الشاشة)
            $table->string('status', 10)->default('planning'); // planning|active|on_hold|completed|cancelled
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('assigned_coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'place_id']);
        });

        Schema::create('project_contractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $table->string('role', 10)->default('main'); // main|sub|consultant|supplier
            $table->string('activity_scope', 500)->nullable(); // وصف مختصر لأعمال المقاول في المشروع
            $table->string('qualification_status', 15)->default('draft'); // draft|pre_review|pre_approved|post_review|post_approved|suspended|expired
            $table->timestamp('pre_approved_at')->nullable();
            $table->timestamp('post_approved_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'external_party_id']);
            $table->index(['external_party_id', 'qualification_status']);
        });

        Schema::create('project_contractor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_contractor_id')->constrained('project_contractors')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('changes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_contractor_id', 'created_at']);
        });

        Schema::create('contractor_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('external_party_id')->unique()->constrained('external_parties')->cascadeOnDelete();
            $t->date('cr_expiry_date')->nullable();
            $t->timestamp('cr_verified_at')->nullable();
            $t->string('insurance_policy_number', 100)->nullable();
            $t->string('insurance_provider', 150)->nullable();
            $t->date('insurance_expiry_date')->nullable();
            $t->timestamp('insurance_verified_at')->nullable();
            $t->string('iso_cert_number', 100)->nullable();
            $t->date('iso_cert_expiry_date')->nullable();
            $t->timestamp('iso_cert_verified_at')->nullable();
            $t->string('gosi_account_number', 50)->nullable();
            $t->timestamp('gosi_verified_at')->nullable();
            $t->string('etimad_entity_number', 50)->nullable();
            $t->timestamp('etimad_verified_at')->nullable();
            $t->boolean('etimad_active')->nullable();
            $t->string('muqawil_classification')->nullable();
            $t->timestamp('muqawil_verified_at')->nullable();
            $t->unsignedTinyInteger('trust_score')->nullable();
            $t->timestamp('trust_score_computed_at')->nullable();
            $t->string('portal_link_token', 64)->nullable()->unique();
            $t->timestamp('portal_link_expires_at')->nullable();
            $t->timestamp('portal_link_used_at')->nullable();
            $t->timestamp('last_enriched_at')->nullable();
            $t->string('last_enriched_channel', 30)->nullable();
            $t->timestamps();
            $t->index('trust_score');
            $t->index('cr_expiry_date');
            $t->index('insurance_expiry_date');
        });

        Schema::create('contractor_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $t->string('field_name', 60);
            $t->text('field_value')->nullable();
            $t->string('source_channel', 20); // pdf_upload|portal_link|etimad|gosi|muqawil|contractor_api|manual
            $t->unsignedTinyInteger('confidence_score')->default(0);
            $t->timestamp('verified_at')->useCurrent();
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('raw_response')->nullable(); // مشفّر
            $t->timestamps();
            $t->index(['external_party_id', 'field_name', 'verified_at']);
            $t->index('expires_at');
        });

        // قنوات التحقق (كانت tenant_contractor_channels): صف لكل قناة، الإعدادات مشفّرة
        Schema::create('contractor_channels', function (Blueprint $t) {
            $t->id();
            $t->string('channel_type', 20)->unique();
            $t->boolean('enabled')->default(false);
            $t->unsignedTinyInteger('priority')->default(50);
            $t->text('config_json')->nullable();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('external_party_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('document_type', 15); // cr|license|insurance|safety_cert|iso_cert|other
            $table->string('file', 500)->nullable();          // اسم الملف الأصلي
            $table->string('file_mime', 100)->nullable();
            $table->longText('file_data')->nullable();        // base64 — قرص Render مؤقت
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_channel', 20)->default('manual');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['external_party_id', 'document_type']);
        });

        Schema::create('external_party_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->integer('safety_score');
            $table->integer('quality_score');
            $table->integer('compliance_score');
            $table->decimal('overall_score', 4, 1);
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('draft'); // draft|approved
            $table->foreignId('evaluated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('manhour_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('external_party_id')->nullable()->constrained('external_parties')->nullOnDelete();
            $table->date('date');
            $table->integer('workers_count');
            $table->decimal('hours_worked', 8, 2);
            $table->decimal('total_manhours', 10, 2);
            $table->integer('incidents_count')->default(0);
            $table->integer('lost_time_incidents')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['project_id', 'external_party_id', 'date']);
        });

        Schema::create('external_party_risks', function (Blueprint $table) {
            $table->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->primary(['external_party_id', 'risk_id']);
        });

        // ── المهن والكفاءات ──
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('qualification_level', 150)->nullable();
            $table->string('level', 12)->default('occupation'); // major|sub_major|minor|unit|occupation
            $table->foreignId('parent_id')->nullable()->constrained('trades')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('trade_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->text('description');
            $table->timestamps();
            $table->index(['trade_id', 'seq']);
        });

        Schema::create('trade_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->string('type', 12); // education|behavioral|technical
            $table->unsignedTinyInteger('seq');
            $table->string('name', 500);
            $table->timestamps();
            $table->index(['trade_id', 'type']);
        });

        Schema::create('competency_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->foreignId('trade_competency_id')->unique()->constrained('trade_competencies')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('trade_risk_categories', function (Blueprint $table) {
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->foreignId('risk_category_id')->constrained('risk_categories')->cascadeOnDelete();
            $table->primary(['trade_id', 'risk_category_id']);
        });

        Schema::create('training_topics', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('category', 15); // induction|permit_control|high_risk|emergency|leadership|ssw|environmental
            $table->text('description')->nullable();
            $table->integer('duration_hours')->default(1);
            $table->integer('validity_months')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('competency_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->foreignId('training_topic_id')->constrained('training_topics')->cascadeOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->string('source', 12)->default('trade_based'); // trade_based|risk_based
            $table->unique(['trade_id', 'training_topic_id']);
        });

        // ── العمال ──
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_party_id')->constrained('external_parties')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: بدل area النصي
            $table->string('full_name', 200);
            $table->string('full_name_en', 200)->nullable();
            $table->string('national_id', 50);
            $table->string('phone', 20)->nullable();
            $table->foreignId('trade_id')->constrained('trades')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft|submitted|induction|training|approved|work_authorized|role_authorized|blocked|suspended
            $table->text('blocked_reason')->nullable();
            $table->date('medical_expiry')->nullable();
            $table->date('iqama_expiry')->nullable();
            $table->string('pin_hash', 128)->nullable();
            $table->date('joined_date')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('national_id');
            $table->index(['external_party_id', 'status']);
            $table->index('place_id');
        });

        Schema::create('worker_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('worker_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('name', 200);
            $table->string('file', 500)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->longText('file_data')->nullable(); // base64
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('worker_training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('training_topic_id')->constrained('training_topics')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending|completed|expired|waived
            $table->date('completed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['worker_id', 'training_topic_id']);
        });

        // ── أعمدة على جداول قائمة ──
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('external_party_id')->nullable()->after('id')->constrained('external_parties')->nullOnDelete();
        });
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('external_party_id')->nullable()->constrained('external_parties')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
        });
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('external_party_id')->nullable()->constrained('external_parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('risks', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::table('risks', fn (Blueprint $t) => $t->dropConstrainedForeignId('external_party_id'));
        Schema::table('incidents', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::table('incidents', fn (Blueprint $t) => $t->dropConstrainedForeignId('external_party_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('external_party_id'));
        foreach (['worker_training_records', 'worker_documents', 'worker_status_events', 'workers', 'competency_requirements', 'training_topics',
            'trade_risk_categories', 'competency_gaps', 'trade_competencies', 'trade_tasks', 'trades', 'external_party_risks', 'manhour_logs',
            'external_party_evaluations', 'external_party_documents', 'contractor_channels', 'contractor_verifications', 'contractor_profiles',
            'project_contractor_events', 'project_contractors', 'projects', 'external_parties'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
