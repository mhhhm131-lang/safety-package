<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٤ «الجولة» (قرار ٨٨ على وثيقة التصميم §٤-١ و§٤-٣):
 *   `form_rounds`                     جولة بطاقة الإدارة لإدارة: رقمها وحالتها (sent لدى الإدارة ← done اعتمدها المدير ← ack الإقرارات جارية ← closed)،
 *                                     من فتحها ومن اعتمدها ومتى، وسجل الخطوات بالاسم والوقت (JSON). تحمل مبناها من فرع الوحدة (`BelongsToBuilding`).
 *   `form_assignments.round_id`       التكليف في جولة؛ والقيد الفريد يصير (النموذج، المكلَّف، الجولة) فيُكلَّف المدير بالبطاقة نفسها كل جولة.
 *   `form_assignments.draft`          حفظ آلي للمسودة على الخادم (تعبئة على أيام)، وتُمسح بالإرسال. لا مسودة في القالب السري.
 *   `form_submissions.round_id`       التعبئة تحمل جولتها (والسرية تحمل الإدارة والجولة فقط — ٢٩-٢).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
            $t->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $t->unsignedSmallInteger('round_no')->default(1);
            $t->string('state', 10)->default('sent'); // sent | done | ack | closed
            $t->foreignId('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('log')->nullable(); // JSON: [{action, by_id, by, at, note}]
            $t->timestamps();
            $t->unique(['organization_unit_id', 'round_no']);
            $t->index(['state']);
        });

        Schema::table('form_assignments', function (Blueprint $t) {
            $t->foreignId('round_id')->nullable()->after('organization_unit_id')->constrained('form_rounds')->nullOnDelete();
            $t->text('draft')->nullable()->after('completed_at');
            $t->timestamp('draft_saved_at')->nullable()->after('draft');
        });
        Schema::table('form_assignments', function (Blueprint $t) {
            $t->dropUnique(['form_id', 'assigned_to_id']);
            $t->unique(['form_id', 'assigned_to_id', 'round_id']);
        });

        Schema::table('form_submissions', function (Blueprint $t) {
            $t->foreignId('round_id')->nullable()->after('organization_unit_id')->constrained('form_rounds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('round_id');
        });
        Schema::table('form_assignments', function (Blueprint $t) {
            $t->dropUnique(['form_id', 'assigned_to_id', 'round_id']);
            $t->unique(['form_id', 'assigned_to_id']);
        });
        Schema::table('form_assignments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('round_id');
            $t->dropColumn(['draft', 'draft_saved_at']);
        });
        Schema::dropIfExists('form_rounds');
    }
};
