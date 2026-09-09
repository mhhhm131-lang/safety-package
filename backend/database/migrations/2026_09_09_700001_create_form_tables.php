<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٧ — النماذج الرقمية (`Modules/Form` من OHSMS، BACKEND.md ٥-٨ و٧-٢).
 * ترحيل واحد لستة جداول بلا tenant_id.
 *
 * غرض الوحدة عندنا (قرار §٥-٨): **التوعية والإقرارات المرتبطة بالمخاطر والاستبيانات**.
 * نماذج الفحص العشرة في المعهد لا تُمس ولا تُستبدل (أنظمة × بنود، قراءات بحدود، جولات دورية، تصعيد بمهلة).
 *
 * إصلاحات مبنية في المخطط (جدول ٥-٨):
 *  - `form_submissions.submitted_at`: كان النموذج يكتبه ولا عمود له في OHSMS فتفشل كل تعبئة.
 *  - `form_fields.help_text/placeholder`: تستعملهما شاشة التعبئة في OHSMS وليسا في القاعدة.
 *  - حقلا التوقيع والصورة: نوعا حقل جديدان، ودليلهما base64 على الرد (قرص Render مؤقت).
 *  - التكليف بالدور والوحدة والمكان: أعمدة على `form_assignments` تحفظ مصدر التكليف.
 *  - «متأخر»: عمود `completed_at` وأمر مجدول يضبط الحالة.
 *  - ربط التعبئة بالتكليف (`submission_id`/`assignment_id`) فيُعرف من عبّأ عن أي تكليف.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── قالب النموذج ──
        Schema::create('form_templates', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->text('intro')->nullable(); // نص يُعرض قبل الحقول (وصف الخطر وضوابطه في الإقرارات)
            // awareness = توعية | confirmation = تأكيد قراءة | declaration = إقرار | declaration_witnessed = إقرار بشاهد | survey = استبيان | custom = مخصص
            $t->string('form_type', 25)->default('custom');
            $t->foreignId('source_risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $t->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['form_type', 'is_active']);
        });

        // ── حقول النموذج ──
        Schema::create('form_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained('form_templates')->cascadeOnDelete();
            $t->string('label', 300);
            $t->string('help_text', 500)->nullable();   // تستعمله شاشة التعبئة (لم يكن في OHSMS)
            $t->string('placeholder', 190)->nullable(); // كذلك
            // text|number|textarea|select|radio|checkbox|date|acknowledge|signature|photo
            $t->string('field_type', 20);
            $t->boolean('is_required')->default(false);
            $t->unsignedSmallInteger('order')->default(0);
            $t->text('options')->nullable(); // JSON لقوائم الاختيار
            $t->timestamps();
            $t->index(['form_id', 'order']);
        });

        // ── تكليف بالنموذج ──
        Schema::create('form_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained('form_templates')->cascadeOnDelete();
            $t->foreignId('assigned_to_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 12)->default('pending'); // pending | completed | overdue
            $t->date('due_date')->nullable();
            // مصدر التكليف: فردي، أو دور، أو وحدة، أو مكان — يُحفظ للتتبع وإعادة الإرسال
            $t->string('source', 12)->default('user');   // user | role | unit | place
            $t->string('source_value', 60)->nullable();  // مفتاح الدور، أو معرّف الوحدة/المكان
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['form_id', 'assigned_to_id']);
            $t->index(['assigned_to_id', 'status']);
            $t->index(['status', 'due_date']);
        });

        // ── تعبئة ──
        Schema::create('form_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained('form_templates')->cascadeOnDelete();
            $t->foreignId('assignment_id')->nullable()->constrained('form_assignments')->nullOnDelete();
            $t->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at'); // كان النموذج يكتبه في OHSMS ولا عمود له
            $t->string('submitted_ip', 45)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['form_id', 'submitted_at']);
        });

        // ── ردّ على حقل ──
        Schema::create('form_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained('form_submissions')->cascadeOnDelete();
            $t->foreignId('field_id')->constrained('form_fields')->cascadeOnDelete();
            $t->text('value')->nullable();
            // التوقيع والصورة: base64 في القاعدة (قرص Render مؤقت — كما البلاغات والتصاريح)
            $t->string('file_name', 190)->nullable();
            $t->string('file_mime', 80)->nullable();
            $t->longText('file_data')->nullable();
            $t->unique(['submission_id', 'field_id']);
        });

        // ── المخاطر التي يغطيها النموذج ──
        Schema::create('form_template_risks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $t->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $t->unique(['form_template_id', 'risk_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'form_template_risks', 'form_answers', 'form_submissions',
            'form_assignments', 'form_fields', 'form_templates',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
