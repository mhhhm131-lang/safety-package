<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢ — المخاطر (BACKEND.md ٥-٥). جداول OHSMS كاملة مجمّعة في ترحيل واحد:
 * التصنيف بثلاث درجات، المتأثرون، المخاطر بأنواعها الثلاثة (master/reference/active)،
 * الملاحظات، سجل الأحداث، المراحل الثلاث بمحاورها، الضوابط.
 * بلا tenant/project/external_party؛ enum → string (Postgres)؛ + place_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('abbreviation', 6)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('risk_sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('risk_categories')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('abbreviation', 6)->nullable();
            $table->boolean('is_universal')->default(true);
            $table->text('description')->nullable();
        });

        Schema::create('risk_causes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_category_id')->nullable()->constrained('risk_sub_categories')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('affected_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->integer('affected_count')->default(0);
            $table->string('vulnerability_level', 10)->default('medium'); // low | medium | high
            $table->text('special_considerations')->nullable();
            $table->timestamps();
        });

        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->string('risk_type', 10)->default('active'); // master | reference | active
            $table->foreignId('parent_reference_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('risk_categories')->nullOnDelete();
            $table->foreignId('sub_category_id')->nullable()->constrained('risk_sub_categories')->nullOnDelete();
            $table->foreignId('risk_type_category_id')->nullable()->constrained('risk_causes')->nullOnDelete();
            $table->integer('severity')->default(1);
            $table->integer('likelihood')->default(1);
            $table->integer('risk_score')->default(1);
            $table->unsignedInteger('incident_count')->default(0);
            $table->timestamp('last_incident_at')->nullable();
            $table->text('benefit')->nullable();
            $table->string('contact_channel', 200)->nullable();
            $table->date('target_closure_date')->nullable();
            $table->string('scope_type', 10)->default('general'); // general | org_unit
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المكان (HZ) — إضافة المعهد
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->text('notes')->nullable();
            $table->string('legal_reference', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_field_team_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['risk_type', 'status']);
            $table->index(['category_id', 'sub_category_id']);
            $table->index('code');
        });

        Schema::create('risk_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('changes')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['risk_id', 'created_at']);
        });

        Schema::create('risk_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 15); // proactive | operational | response
            $table->text('preventive_action')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('residual_assessment')->nullable();
            $table->foreignId('responsible_org_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('responsible_org_unit_text', 200)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_user_text', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['risk_id', 'phase']);
        });

        Schema::create('risk_phase_causes', function (Blueprint $table) {
            $table->foreignId('risk_phase_id')->constrained('risk_phases')->cascadeOnDelete();
            $table->foreignId('risk_cause_id')->constrained('risk_causes')->cascadeOnDelete();
            $table->primary(['risk_phase_id', 'risk_cause_id']);
        });

        Schema::create('risk_phase_affected_groups', function (Blueprint $table) {
            $table->foreignId('risk_phase_id')->constrained('risk_phases')->cascadeOnDelete();
            $table->foreignId('affected_group_id')->constrained('affected_groups')->cascadeOnDelete();
            $table->primary(['risk_phase_id', 'affected_group_id']);
        });

        Schema::create('risk_phase_affected_group_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_phase_id')->constrained('risk_phases')->cascadeOnDelete();
            $table->foreignId('affected_group_id')->constrained('affected_groups')->cascadeOnDelete();
            $table->string('impact', 20)->default('medium');
            $table->string('rep_scope', 50)->nullable();
            $table->text('impact_description')->nullable();
            $table->text('details')->nullable();
            $table->text('cascading_effects')->nullable();
            $table->unique(['risk_phase_id', 'affected_group_id']);
        });

        Schema::create('risk_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->foreignId('risk_category_id')->nullable()->constrained('risk_categories')->nullOnDelete();
            $table->string('permit_type_code', 60)->nullable();
            $table->string('phase', 15); // preventive | operational | response
            $table->string('description_ar');
            $table->string('description_en')->nullable();
            $table->string('evidence_type', 20)->default('check'); // check | measurement | photo | signature | document
            $table->string('measurement_unit')->nullable();
            $table->string('measurement_threshold')->nullable();
            $table->string('responsible_role', 30)->default('issuer');
            $table->string('frequency')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('standard_reference')->nullable();
            $table->boolean('review_flag')->default(false);
            $table->text('review_notes')->nullable();
            $table->unsignedSmallInteger('flag_count')->default(0);
            $table->timestamps();
            $table->index(['risk_id', 'phase']);
            $table->index(['risk_category_id', 'phase']);
        });
    }

    public function down(): void
    {
        foreach (['risk_controls', 'risk_phase_affected_group_details', 'risk_phase_affected_groups', 'risk_phase_causes',
            'risk_phases', 'risk_events', 'risk_notes', 'risks', 'affected_groups', 'risk_causes', 'risk_sub_categories', 'risk_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
