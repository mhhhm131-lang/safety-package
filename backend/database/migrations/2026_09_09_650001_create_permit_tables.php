<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٦-ب — التصاريح (Modules/Permit «Hub» من OHSMS + مناطق العمل وعمال التصريح وجاهزية البوابة من WorkPermit + المعدات من EPC).
 * BACKEND.md ٥-٦ و٧-٢. ترحيل واحد لستة عشر جدولاً (كانت ٤١ ترحيلاً في OHSMS) بلا tenant_id ولا الأنشطة الاقتصادية.
 *
 * قرارات المعهد في هذا الترحيل:
 *  - **مناطق العمل = الأماكن التسعة** (٥-٦): لا جدول `work_zones`؛ `permits.place_id` يشير إلى `places`،
 *    وسعة المكان (`max_workers`/`max_equipment`) عمودان على `places` **بلا قيم افتراضية** (null = بلا حد؛ الأرقام قرار المستخدم).
 *  - قواعد التعارض بالمكان (`permit_type_conflict_rules.place_id`) بدل منطقة العمل.
 *  - `permit_workers` تُربط بجدول `permits` مباشرة (لا نظام WorkPermit القديم، ولا عمود `hub_permit_id`).
 *  - المرفقات وأدلة البنود base64 في القاعدة (قرص Render مؤقت — كما البلاغات والمقاولون).
 *  - enum → نص + تحقق في الكود (Postgres/SQLite).
 *  - `incidents.permit_id` للربط البعدي (تقييم ما بعد الإغلاق يربط بلاغات فترة التصريح).
 *
 * ما لم يُنقل من OHSMS عمداً: `work_permits` و`work_permit_requests` (النظام القديم المؤرشف بالتعليقات في OHSMS نفسه)،
 * `conflict_rules` القديم (كان `permit_type_a_id` يشير خطأً إلى `risk_categories`)، والأنشطة الاقتصادية.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── كتالوج أنواع التصاريح (بيانات مرجعية عامة) ──
        Schema::create('permit_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();
            $t->string('name', 200);
            $t->string('name_en', 200)->nullable();
            $t->string('category', 15); // qualification|work|worker|equipment|special
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('default_validity_days')->nullable();
            $t->boolean('requires_project')->default(false);
            $t->boolean('requires_contractor')->default(false);
            $t->boolean('requires_place')->default(false);   // كان requires_zone
            $t->boolean('requires_worker')->default(false);
            $t->boolean('requires_equipment')->default(false);
            $t->boolean('two_stage_approval')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['category', 'is_active']);
        });

        // ── المعدات (من EPC — موضوع تصاريح تشغيل المعدات والرافعات) ──
        Schema::create('equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // مكانها في المعهد
            $t->foreignId('external_party_id')->nullable()->constrained('external_parties')->nullOnDelete(); // معدة مقاول
            $t->string('name', 200);
            $t->string('code', 60)->nullable();
            $t->string('equipment_type', 30)->nullable(); // heavy|light|electrical|safety|lifting|other
            $t->string('serial_number', 120)->nullable();
            $t->string('manufacturer', 120)->nullable();
            $t->string('model_number', 120)->nullable();
            $t->string('status', 20)->default('active'); // active|maintenance|retired|out_of_service
            $t->unsignedSmallInteger('inspection_frequency_days')->nullable();
            $t->date('last_inspection_date')->nullable();
            $t->date('next_inspection_date')->nullable();
            $t->string('location', 200)->nullable();
            $t->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'place_id']);
        });

        Schema::create('equipment_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->date('inspection_date');
            $t->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('result', 15); // pass|fail|conditional
            $t->text('findings')->nullable();
            $t->date('next_inspection')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['equipment_id', 'inspection_date']);
        });

        // ── التصريح الموحّد: خمس فئات في جدول واحد ──
        Schema::create('permits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_type_id')->constrained('permit_types')->cascadeOnDelete();
            $t->string('permit_category', 15);
            $t->string('scope', 20)->nullable(); // project|contractor_pre|contractor_post|individual
            $t->foreignId('parent_permit_id')->nullable()->constrained('permits')->nullOnDelete();
            $t->string('code', 30)->unique();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignId('external_party_id')->nullable()->constrained('external_parties')->nullOnDelete();
            $t->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // منطقة العمل = المكان
            $t->string('subject_type', 30)->nullable(); // OrganizationUnit|ExternalParty|Worker|Equipment|Project
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedSmallInteger('workers_count')->nullable();
            $t->unsignedSmallInteger('equipment_count')->nullable();
            $t->text('location_description')->nullable();
            $t->string('sub_location', 200)->nullable(); // الموضع الدقيق داخل المكان (لوحة، خزان، غرفة)
            $t->text('precautions')->nullable();
            $t->text('additional_notes')->nullable();
            $t->string('requester_name', 150)->nullable();
            $t->string('requester_phone', 30)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('safety_approved_at')->nullable();
            $t->foreignId('safety_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('metadata')->nullable();              // JSON: التقييم البعدي وغيره
            $t->text('activation_conditions')->nullable();  // JSON: لقطة ظروف لحظة التفعيل
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['permit_category', 'status']);
            $t->index(['place_id', 'status']);
            $t->index(['project_id', 'external_party_id', 'status']);
            $t->index(['subject_type', 'subject_id']);
            $t->index('expires_at');
        });

        // ── بنود التصريح (JSA الثلاثي الأطوار + الوثائق والتدريب) ──
        Schema::create('permit_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->string('category', 30); // document|training|certificate|risk_control|prerequisite_permit|worker_check|equipment_check|qualification|other
            $t->string('requirement_code', 120);
            $t->string('description_ar', 500)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->foreignId('risk_control_id')->nullable()->constrained('risk_controls')->nullOnDelete();
            $t->string('phase', 15)->nullable();          // preventive|operational|response
            $t->string('evidence_type', 15)->nullable();  // check|measurement|photo|signature|document
            $t->string('responsible_role', 30)->nullable();
            $t->string('frequency', 30)->nullable();
            $t->string('status', 15)->default('required'); // required|in_progress|completed|waived|failed
            $t->string('severity', 15)->default('mandatory'); // mandatory|recommended
            $t->string('evidence_value', 500)->nullable();
            $t->boolean('measurement_passed')->nullable();
            // دليل مرفوع — base64 في القاعدة (قرص Render مؤقت)
            $t->string('evidence_file', 190)->nullable();
            $t->string('evidence_file_mime', 80)->nullable();
            $t->longText('evidence_file_data')->nullable();
            $t->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['permit_id', 'requirement_code']);
            $t->index(['permit_id', 'status']);
        });

        // ── السجل الزمني للتصريح (يُكتب فقط عبر PermitService) ──
        Schema::create('permit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->string('event_type', 40);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->text('changes')->nullable(); // JSON
            $t->text('notes')->nullable();
            $t->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('signature_ip', 45)->nullable();
            $t->decimal('signature_lat', 10, 7)->nullable();
            $t->decimal('signature_lng', 10, 7)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['permit_id', 'created_at']);
        });

        // ── مخاطر التصريح (من السجل الفعّال والكتاب العام) ──
        Schema::create('permit_risks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $t->boolean('auto_suggested')->default(false);
            $t->timestamp('added_at')->nullable();
            $t->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->unique(['permit_id', 'risk_id']);
        });

        // ── مهن التصريح (تضيّق بنود التحكم المقترحة) ──
        Schema::create('permit_trades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $t->unique(['permit_id', 'trade_id']);
        });

        // ── عمال التصريح (من WorkPermit — مربوطة بالتصريح الموحّد مباشرة) ──
        Schema::create('permit_workers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $t->string('qualification_status', 20)->default('qualified'); // qualified|warning|not_qualified
            $t->text('qualification_notes')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->unique(['permit_id', 'worker_id']);
        });

        // ── الانحرافات الميدانية (ما اختلف عن المخطط أثناء العمل) ──
        Schema::create('permit_deviations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->foreignId('permit_requirement_id')->nullable()->constrained('permit_requirements')->nullOnDelete();
            $t->string('type', 30)->default('other');
            $t->text('description');
            $t->string('severity', 10)->default('medium'); // low|medium|high
            $t->text('corrective_action_taken')->nullable();
            $t->string('status', 10)->default('open'); // open|resolved|accepted
            $t->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recorded_at')->nullable();
            $t->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->string('signature_ip', 45)->nullable();
            $t->index(['permit_id', 'status']);
        });

        // ── مرفقات التصريح (base64) ──
        Schema::create('permit_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_id')->constrained('permits')->cascadeOnDelete();
            $t->string('name', 190);
            $t->string('original_name', 190)->nullable();
            $t->string('mime', 80);
            $t->unsignedInteger('size');
            $t->longText('data'); // base64
            $t->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });

        // ── قواعد التعارض بين نوعي تصريح (بالمكان أو عامة) ──
        Schema::create('permit_type_conflict_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_type_a_id')->constrained('permit_types')->cascadeOnDelete();
            $t->foreignId('permit_type_b_id')->constrained('permit_types')->cascadeOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // null = تسري في كل مكان
            $t->string('severity', 10)->default('block'); // block|warn
            $t->text('reason')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['permit_type_a_id', 'is_active']);
            $t->index(['permit_type_b_id', 'is_active']);
        });

        // ── الخطر (أو فئته) يستلزم نوع تصريح ──
        Schema::create('risk_required_permit_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('risk_id')->nullable()->constrained('risks')->cascadeOnDelete();
            $t->foreignId('risk_category_id')->nullable()->constrained('risk_categories')->cascadeOnDelete();
            $t->foreignId('permit_type_id')->constrained('permit_types')->cascadeOnDelete();
            $t->boolean('is_mandatory')->default(true);
            $t->string('triggering_condition', 20)->default('always'); // always|severity_ge_2..5|requires_trade
            $t->foreignId('trade_id')->nullable()->constrained('trades')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['risk_category_id', 'permit_type_id']);
            $t->index(['risk_id', 'permit_type_id']);
        });

        // ── المهنة تستلزم نوع تصريح ──
        Schema::create('permit_type_trades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_type_id')->constrained('permit_types')->cascadeOnDelete();
            $t->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $t->boolean('is_mandatory')->default(true);
            $t->string('triggering_condition', 20)->default('always');
            $t->timestamps();
            $t->unique(['permit_type_id', 'trade_id']);
        });

        // ── كتالوج بنود التأهيل (لتصاريح فئة qualification) ──
        Schema::create('qualification_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permit_type_id')->constrained('permit_types')->cascadeOnDelete();
            $t->string('phase', 15)->default('preventive');
            $t->string('description_ar', 500);
            $t->string('description_en', 500)->nullable();
            $t->string('evidence_type', 15)->default('document');
            $t->boolean('is_mandatory')->default(true);
            $t->string('standard_reference', 190)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['permit_type_id', 'phase']);
        });

        // ── جاهزية البوابة: سجل كل فحص عامل قبل الدخول للعمل ──
        Schema::create('gate_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('worker_id')->nullable()->constrained('workers')->cascadeOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $t->foreignId('permit_id')->nullable()->constrained('permits')->nullOnDelete();
            $t->string('gate_name', 100)->default('main');
            $t->string('result', 10); // allowed|denied
            $t->text('denial_reason')->nullable();
            $t->text('checks')->nullable(); // JSON
            $t->foreignId('scanned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['result', 'created_at']);
        });

        // ── سعة المكان (منطقة العمل) — بلا قيم افتراضية؛ null = بلا حد ──
        Schema::table('places', function (Blueprint $t) {
            $t->unsignedSmallInteger('max_workers')->nullable();
            $t->unsignedSmallInteger('max_equipment')->nullable();
        });

        // ── ربط البلاغ بالتصريح (تقييم ما بعد الإغلاق يربط بلاغات فترة التصريح) ──
        Schema::table('incidents', function (Blueprint $t) {
            $t->foreignId('permit_id')->nullable()->constrained('permits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $t) => $t->dropConstrainedForeignId('permit_id'));
        Schema::table('places', fn (Blueprint $t) => $t->dropColumn(['max_workers', 'max_equipment']));
        foreach ([
            'gate_logs', 'qualification_checklist_items', 'permit_type_trades', 'risk_required_permit_types',
            'permit_type_conflict_rules', 'permit_attachments', 'permit_deviations', 'permit_workers',
            'permit_trades', 'permit_risks', 'permit_events', 'permit_requirements', 'permits',
            'equipment_inspections', 'equipment', 'permit_types',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
