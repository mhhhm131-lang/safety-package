<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٤ — الطوارئ (Modules/Emergency من OHSMS) حول نواة المعهد.
 *
 * ترحيل واحد بدل ٢٨ في OHSMS (١٥ في database/migrations + ١٣ داخل الوحدة لم تكن تُحمَّل — الخلل المؤكد في ٥-٣).
 * بلا tenant/project. كل enum صار string (تعارضات Postgres المعروفة، ٧-٤).
 *
 * ما لم يُنقل من جداول OHSMS (قرار ٢٠٢٦-٠٩-٠٧، BACKEND.md ٥-٣): لمّ الشمل الأسري (٣ جداول) وجهات اتصال الأسرة (٢)،
 * والكاميرات والأساور (٣) والسياج الجغرافي والمواقع الداخلية (٣ + ble_beacons) — الأجهزة للمرحلة ٥.
 *
 * إضافات المعهد (موثقة عند كل عمود): place_id (الحالة الطارئة تُربط بالمكان HZ لا بالطابق فقط)؛ الفريق الأولي
 * مشتق من ملف المكان فأعضاؤه بلا حساب (user_id nullable + name/role_key/trained_at/trainer)؛ آلة الحالة
 * (contained_by/cancelled_by/cancel_reason)؛ التصعيد الآلي (escalation_level/escalated_at/acknowledged_*)؛
 * قيادة الحادث (ics_data)؛ جدول lockdowns بدل الكاش؛ مرفقات تنبيه الذعر في القاعدة (Render بلا قرص دائم).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->text('address')->nullable();
            $table->string('building_type', 20)->default('government'); // office|industrial|educational|medical|residential|commercial|government|other
            $table->unsignedTinyInteger('floors_count')->default(1);
            $table->unsignedTinyInteger('basement_floors')->default(0);
            $table->unsignedInteger('total_capacity')->nullable();
            $table->unsignedInteger('current_occupants')->default(0);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('floor_plan_file', 255)->nullable();
            $table->string('status', 20)->default('active'); // active|inactive|under_maintenance
            $table->string('risk_level', 10)->default('medium');
            $table->date('last_audit_date')->nullable();
            $table->date('next_audit_date')->nullable();
            $table->string('emergency_status', 15)->default('normal'); // normal|alert|evacuating|all_clear
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('emergency_status');
        });

        Schema::create('building_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->tinyInteger('floor_number');
            $table->string('name', 100)->nullable();
            $table->string('zone', 100)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('current_occupants')->default(0);
            $table->unsignedInteger('evacuation_time_sec')->nullable();
            $table->unsignedTinyInteger('evacuation_order')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 15)->default('normal'); // normal|evacuating|cleared|blocked
            $table->string('floor_plan_file', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['building_id', 'floor_number']);
            $table->index(['building_id', 'status']);
        });

        Schema::create('assembly_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: نقطة التجمع الأقرب لمكان
            $table->string('code', 10);
            $table->string('name', 100);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_accessible')->default(true);
            $table->text('directions')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('active'); // active|inactive
            $table->timestamp('created_at')->nullable();
            $table->index(['building_id', 'is_primary']);
        });

        Schema::create('building_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('floor_id')->constrained('building_floors')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 100)->nullable();
            $table->string('exit_type', 15)->default('emergency'); // main|emergency|fire_escape|service
            $table->string('direction', 50)->nullable();
            $table->decimal('width_meters', 4, 2)->nullable();
            $table->unsignedInteger('capacity_per_min')->nullable();
            $table->boolean('is_accessible')->default(false);
            $table->foreignId('leads_to_point_id')->nullable()->constrained('assembly_points')->nullOnDelete();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('status', 15)->default('available'); // available|blocked|maintenance
            $table->timestamp('created_at')->nullable();
            $table->index(['building_id', 'status']);
            $table->index(['floor_id', 'status']);
        });

        Schema::create('emergency_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: فريق المكان
            $table->string('name', 200);
            // OHSMS: command|fire_warden|first_aid|evacuation|search_rescue|communication|security — المعهد: initial (الفريق الأولي الرباعي)
            $table->string('team_type', 20);
            $table->text('description')->nullable();
            $table->string('shift', 10)->default('all'); // morning|evening|night|all
            $table->boolean('is_active')->default(true);
            // المعهد: الفريق الأولي يُشتق من ملف المكان (ipa-place) — لا إدخال مزدوج (BACKEND.md ٥-٣)
            $table->string('source', 20)->default('manual'); // manual|place_profile
            $table->string('unit_key', 40)->nullable();       // مفتاح وحدة الفريق في ملف المكان ('_' أو رمز الإدارة)
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('readiness', 15)->default('none'); // none|nominated|approved|referred (مسار الترشيح ← الاعتماد ← الإحالة في اللوحة)
            $table->timestamp('created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->index(['building_id', 'team_type']);
            $table->index(['place_id', 'is_active']);
            $table->unique(['source', 'place_id', 'unit_key']);
        });

        Schema::create('emergency_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('emergency_teams')->cascadeOnDelete();
            // المعهد: أعضاء الفريق الأولي ميدانيون بلا حساب رقمي (BACKEND.md ٤-٣ ب) → الحساب اختياري والاسم إلزامي
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('role', 10)->default('member'); // leader|deputy|member
            $table->string('role_key', 20)->nullable();   // coordinator|medic|rescuer|firefighter (الأدوار الأربعة في ملف المكان)
            $table->string('specialization', 100)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_emergency', 20)->nullable();
            $table->boolean('is_backup')->default(false);
            $table->boolean('is_available')->default(true);
            $table->json('certifications')->nullable();
            $table->date('trained_at')->nullable();
            $table->string('trainer', 120)->nullable();
            $table->date('training_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['team_id', 'role']);
            $table->index(['team_id', 'user_id']);
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->string('contact_type', 10); // internal|external
            $table->string('name', 200);
            $table->string('role', 100)->nullable();
            $table->string('organization', 200)->nullable();
            $table->string('phone', 20);
            $table->string('phone_alt', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->boolean('auto_notify')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['contact_type', 'is_active']);
            $table->index(['building_id', 'priority']);
        });

        Schema::create('emergency_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: المكان الذي وقعت فيه الحالة
            $table->string('incident_code', 30)->unique();
            // fire|evacuation|chemical_spill|medical|earthquake|flood|security|bomb_threat|gas_leak|structural|drill|lockdown|other
            $table->string('incident_type', 20);
            $table->string('severity', 10)->default('high');
            $table->string('status', 15)->default('active'); // active|contained|ended|cancelled (آلة الحالة)
            $table->boolean('is_drill')->default(false);
            $table->timestamp('triggered_at');
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contained_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->json('affected_floors')->nullable();
            $table->unsignedInteger('evacuation_time_sec')->nullable();
            $table->unsignedInteger('total_evacuees')->nullable();
            $table->unsignedInteger('total_safe')->nullable();
            $table->unsignedInteger('total_injured')->nullable();
            $table->unsignedInteger('total_missing')->nullable();
            $table->text('description')->nullable();
            $table->text('initial_report')->nullable();
            $table->text('final_report')->nullable();
            $table->foreignId('linked_incident_id')->nullable()->constrained('incidents')->nullOnDelete(); // بلاغ شاغل عاجل فعّل الحالة
            // التصعيد الآلي (AutoEscalationService كان يقرأ أعمدة غير موجودة في OHSMS — هنا تُنشأ)
            $table->unsignedTinyInteger('escalation_level')->default(1);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ics_data')->nullable(); // قيادة الحادث (IncidentCommandService)
            $table->timestamps();
            $table->index(['building_id', 'status']);
            $table->index(['place_id', 'status']);
            $table->index('triggered_at');
        });

        Schema::create('emergency_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('emergency_incidents')->cascadeOnDelete();
            // alarm_triggered|floor_cleared|person_safe|person_missing|person_found|help_requested|team_notified|team_arrived
            // |external_notified|contained|all_clear|note|status_change|escalation|cancelled|lockdown|ics|message|panic|visitor
            $table->string('event_type', 25);
            $table->string('severity', 10)->default('info'); // info|warning|critical
            $table->text('message');
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('logged_at');
            $table->index(['incident_id', 'logged_at']);
            $table->index(['incident_id', 'event_type']);
        });

        Schema::create('evacuation_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('emergency_incidents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained('emergency_team_members')->nullOnDelete(); // المعهد: عضو فريق بلا حساب
            $table->string('visitor_name', 200)->nullable();
            $table->string('visitor_phone', 20)->nullable();
            $table->string('visitor_company', 200)->nullable();
            $table->string('person_type', 15)->default('employee'); // employee|contractor|visitor|team
            $table->string('qr_token', 64)->unique();
            $table->timestamp('qr_generated_at')->nullable();
            $table->string('status', 15)->default('evacuating'); // evacuating|safe|missing|injured|assisted|deceased
            $table->string('response_status', 20)->default('pending'); // pending|safe|need_help|evacuating|not_in_building
            $table->timestamp('response_at')->nullable();
            $table->text('response_message')->nullable();
            $table->foreignId('floor_id')->nullable()->constrained('building_floors')->nullOnDelete();
            $table->string('last_known_location', 200)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('assembly_point_id')->nullable()->constrained('assembly_points')->nullOnDelete();
            $table->foreignId('checked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('check_in_method', 10)->nullable(); // qr_scan|self|manual|auto
            $table->decimal('check_in_lat', 10, 8)->nullable();
            $table->decimal('check_in_lng', 11, 8)->nullable();
            $table->boolean('needs_assistance')->default(false);
            $table->string('assistance_type', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['incident_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('evacuation_drills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: التمرين على خطة استجابة مكان
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->string('drill_code', 30)->unique();
            $table->string('drill_type', 15)->default('fire'); // fire|evacuation|earthquake|chemical|full_scale|tabletop|announced|unannounced
            $table->text('scenario')->nullable();
            $table->text('objectives')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 15)->default('scheduled'); // scheduled|in_progress|completed|cancelled|postponed
            $table->unsignedInteger('expected_participants')->nullable();
            $table->unsignedInteger('actual_participants')->nullable();
            $table->unsignedInteger('evacuation_time_sec')->nullable();
            $table->unsignedInteger('target_time_sec')->nullable();
            $table->string('result', 20)->nullable(); // pass|fail|needs_improvement
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('observations')->nullable();
            $table->text('issues_found')->nullable();
            $table->text('improvements')->nullable();
            $table->foreignId('conducted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_file', 255)->nullable();
            $table->timestamps();
            $table->index(['building_id', 'scheduled_at']);
            $table->index('status');
        });

        Schema::create('drill_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drill_id')->constrained('evacuation_drills')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_name', 120)->nullable();
            $table->string('visitor_phone', 20)->nullable();
            $table->string('role', 15)->default('participant'); // participant|observer|evaluator|team_member
            $table->string('status', 15)->default('registered'); // registered|evacuated
            $table->timestamp('evacuated_at')->nullable();
            $table->foreignId('floor_id')->nullable()->constrained('building_floors')->nullOnDelete();
            $table->unsignedInteger('evacuation_time_sec')->nullable();
            $table->foreignId('reached_point_id')->nullable()->constrained('assembly_points')->nullOnDelete();
            $table->text('feedback')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['drill_id', 'role']);
        });

        Schema::create('emergency_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained('building_floors')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المعهد: المعدة في مكان
            // fire_extinguisher|fire_hose|smoke_detector|heat_detector|alarm_bell|exit_sign|emergency_light|first_aid_kit|aed|fire_blanket|spill_kit|eyewash|other
            $table->string('equipment_type', 20);
            $table->string('code', 50)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('location_description', 255)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->date('install_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('last_inspection_date')->nullable();
            $table->date('next_inspection_date')->nullable();
            $table->string('inspection_frequency', 15)->default('monthly'); // monthly|quarterly|semi_annual|annual
            $table->string('status', 20)->default('operational'); // operational|needs_service|out_of_service|expired|missing
            $table->text('notes')->nullable();
            $table->string('qr_code', 64)->nullable();
            $table->timestamps();
            $table->index(['building_id', 'equipment_type']);
            $table->index('next_inspection_date');
            $table->index('status');
        });

        Schema::create('emergency_equipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('emergency_equipment')->cascadeOnDelete();
            $table->timestamp('inspected_at');
            $table->foreignId('inspected_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('result', 20); // pass|fail|needs_attention
            $table->json('checklist')->nullable();
            $table->text('issues_found')->nullable();
            $table->text('corrective_action')->nullable();
            $table->date('next_inspection_date')->nullable();
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['equipment_id', 'inspected_at']);
        });

        // سجل ما أُرسل (داخل النظام/بريد/نداء هاتفي يدوي) — القناتان المقررتان §٦؛ لا SMS ولا واتساب
        Schema::create('emergency_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->string('channel', 15); // in_app|email|phone_call|push|sms|whatsapp (الأخيرة ثلاثتها تُسجَّل «غير متاحة»)
            $table->string('recipient_type', 15); // user|team_member|contact|role|all
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_name', 200)->nullable();
            $table->string('recipient_contact', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->string('status', 15)->default('pending'); // pending|sent|delivered|failed|manual
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['incident_id', 'channel']);
            $table->index('status');
        });

        // جدول الإغلاق الأمني بدل الكاش (الإصلاح المقرر ٥-٣) — أفعال الأجهزة (أبواب/مصاعد/شاشات) تُوصل في المرحلة ٥
        Schema::create('lockdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->string('level', 10); // soft|modified|full|shelter|zone
            $table->string('state', 10)->default('active'); // active|partial|lifted
            $table->json('zones')->nullable();
            $table->json('options')->nullable();
            $table->json('results')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at');
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamps();
            $table->index(['building_id', 'state']);
        });

        Schema::create('panic_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->string('location_description', 255)->nullable();
            $table->string('alert_type', 10)->default('panic'); // panic|medical|fire|security|other
            $table->string('severity', 10)->default('high');
            $table->string('status', 15)->default('triggered'); // triggered|acknowledged|responding|resolved|false_alarm
            $table->text('message')->nullable();
            $table->string('voice_mime', 60)->nullable();
            $table->longText('voice_data')->nullable(); // base64 — لا قرص دائم على Render
            $table->string('photo_mime', 60)->nullable();
            $table->longText('photo_data')->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('user_id');
            $table->index('created_at');
        });

        Schema::create('panic_alert_responders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panic_alert_id')->constrained('panic_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('notified_at')->useCurrent();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_type', 15)->nullable(); // acknowledged|en_route|arrived|unavailable
            $table->timestamps();
            $table->index('panic_alert_id');
            $table->index('user_id');
        });

        Schema::create('emergency_mass_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->string('title', 200);
            $table->text('message');
            $table->string('message_type', 15)->default('alert'); // alert|update|instruction|all_clear
            $table->string('target_type', 10)->default('all'); // all|building|floor|team|place|custom
            $table->foreignId('target_building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('target_floor_id')->nullable()->constrained('building_floors')->nullOnDelete();
            $table->unsignedBigInteger('target_team_id')->nullable();
            $table->foreignId('target_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->json('channels'); // ["app","email"] — push/sms/whatsapp تُسجَّل غير متاحة
            $table->integer('total_recipients')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('read_count')->default(0);
            $table->integer('responded_count')->default(0);
            $table->foreignId('sent_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
            $table->index('incident_id');
            $table->index('sent_at');
        });

        Schema::create('emergency_message_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('emergency_mass_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('response_type', 15); // safe|need_help|evacuating|not_present|custom
            $table->text('response_text')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('emergency_message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->string('category', 15); // fire|earthquake|medical|security|weather|chemical|evacuation|drill|all_clear|custom
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('message_ar');
            $table->text('message_en')->nullable();
            $table->json('variables')->nullable();
            $table->json('default_channels')->nullable();
            $table->string('severity', 10)->default('high');
            $table->boolean('auto_trigger')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_builtin')->default(false); // بدل tenant_id null = قالب عام
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('category');
        });

        Schema::create('emergency_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('name', 100);
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('company', 100)->nullable();
            $table->string('id_number', 50)->nullable();
            $table->string('id_type', 30)->nullable();
            $table->string('photo_mime', 60)->nullable();
            $table->longText('photo_data')->nullable();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 200)->nullable();
            $table->string('badge_number', 20)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->timestamp('checked_in_at')->useCurrent();
            $table->timestamp('expected_checkout_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('qr_token', 64)->unique();
            $table->string('evacuation_status', 15)->default('unknown'); // unknown|safe|evacuating|need_help|missing
            $table->timestamp('evacuation_checked_at')->nullable();
            $table->string('evacuation_location', 200)->nullable();
            $table->foreignId('evacuation_assembly_point_id')->nullable()->constrained('assembly_points')->nullOnDelete();
            $table->boolean('needs_assistance')->default(false);
            $table->string('assistance_type', 100)->nullable();
            $table->text('special_notes')->nullable();
            $table->string('status', 15)->default('checked_in'); // checked_in|checked_out|denied|blacklisted
            $table->timestamps();
            $table->index('building_id');
            $table->index('checked_in_at');
            $table->index('evacuation_status');
            $table->index('status');
        });

        Schema::create('emergency_medical_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('blood_type', 5)->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('current_medications')->nullable();
            $table->boolean('has_pacemaker')->default(false);
            $table->boolean('has_hearing_aid')->default(false);
            $table->boolean('wears_glasses')->default(false);
            $table->boolean('uses_wheelchair')->default(false);
            $table->boolean('uses_cane_walker')->default(false);
            $table->string('mobility_level', 12)->default('full'); // full|limited|wheelchair|bedridden
            $table->boolean('needs_evacuation_assistance')->default(false);
            $table->string('assistance_requirements', 500)->nullable();
            $table->string('emergency_contact_1_name', 100)->nullable();
            $table->string('emergency_contact_1_phone', 20)->nullable();
            $table->string('emergency_contact_1_relation', 50)->nullable();
            $table->string('emergency_contact_2_name', 100)->nullable();
            $table->string('emergency_contact_2_phone', 20)->nullable();
            $table->string('emergency_contact_2_relation', 50)->nullable();
            $table->string('primary_physician_name', 100)->nullable();
            $table->string('primary_physician_phone', 20)->nullable();
            $table->string('preferred_hospital', 200)->nullable();
            $table->string('insurance_provider', 100)->nullable();
            $table->string('insurance_policy_number', 50)->nullable();
            $table->text('medical_notes')->nullable();
            $table->text('evacuation_instructions')->nullable();
            $table->string('dnr_status', 20)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('share_with_responders')->default(true);
            $table->boolean('share_with_medical_team')->default(true);
            $table->timestamp('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('blood_type');
            $table->index('needs_evacuation_assistance');
        });

        Schema::create('after_action_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('emergency_incidents')->cascadeOnDelete();
            $table->foreignId('building_id')->constrained('emergency_buildings')->cascadeOnDelete();
            $table->string('report_number', 30)->unique();
            $table->string('title', 200);
            $table->string('status', 15)->default('draft'); // draft|under_review|approved|published
            $table->string('severity', 10)->default('moderate'); // minor|moderate|major|critical
            $table->timestamp('incident_start_at');
            $table->timestamp('incident_end_at')->nullable();
            $table->integer('response_time_minutes')->nullable();
            $table->integer('evacuation_time_minutes')->nullable();
            $table->integer('resolution_time_minutes')->nullable();
            $table->integer('total_occupants')->default(0);
            $table->integer('evacuated_count')->default(0);
            $table->integer('injuries_count')->default(0);
            $table->integer('fatalities_count')->default(0);
            $table->integer('missing_count')->default(0);
            $table->integer('property_damage_estimate')->nullable();
            $table->text('incident_description');
            $table->text('root_cause_analysis')->nullable();
            $table->text('chronology')->nullable();
            $table->text('immediate_actions_taken')->nullable();
            $table->integer('notification_effectiveness_score')->nullable();
            $table->integer('evacuation_effectiveness_score')->nullable();
            $table->integer('communication_effectiveness_score')->nullable();
            $table->integer('leadership_effectiveness_score')->nullable();
            $table->integer('equipment_effectiveness_score')->nullable();
            $table->decimal('overall_score', 3, 1)->nullable();
            $table->text('what_went_well')->nullable();
            $table->text('what_went_wrong')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->text('recommendations')->nullable();
            $table->boolean('osha_reportable')->default(false); // OHSMS؛ عندنا: يُبلَّغ للجهة المختصة (الدفاع المدني/HRSD)
            $table->boolean('regulatory_notification_required')->default(false);
            $table->boolean('regulatory_notification_sent')->default(false);
            $table->timestamp('regulatory_notification_sent_at')->nullable();
            $table->text('evidence_files')->nullable();
            $table->text('witness_statements')->nullable();
            $table->foreignId('prepared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_comments')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('incident_start_at');
        });

        Schema::create('aar_corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('after_action_reports')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->string('priority', 10)->default('medium');
            $table->string('category', 20)->default('other'); // training|equipment|procedure|communication|infrastructure|staffing|documentation|other
            $table->string('status', 15)->default('open'); // open|in_progress|completed|cancelled
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->text('completion_notes')->nullable();
            $table->integer('estimated_cost')->nullable();
            $table->integer('actual_cost')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        foreach ([
            'aar_corrective_actions', 'after_action_reports', 'emergency_medical_profiles', 'emergency_visitors',
            'emergency_message_templates', 'emergency_message_responses', 'emergency_mass_messages',
            'panic_alert_responders', 'panic_alerts', 'lockdowns', 'emergency_notifications',
            'emergency_equipment_inspections', 'emergency_equipment', 'drill_participants', 'evacuation_drills',
            'evacuation_check_ins', 'emergency_event_logs', 'emergency_incidents', 'emergency_contacts',
            'emergency_team_members', 'emergency_teams', 'building_exits', 'assembly_points', 'building_floors',
            'emergency_buildings',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
