<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قرار ٨٠ (بكلمته «موافق» ٢٠٢٦-١٠-٠٨): «الفرع» في الخاص كـ«الإدارة» — يتعبّأ تلقائياً عند التفعيل من رأس وحدة التفعيل؛
 * وتبديل الإدارة المعالجة في نسخة الفرع: يقترحه منسق سلامة الفرع ويعتمده مدير الفرع (ومسؤول السلامة يبدّل مباشرة).
 * النسخ الفعلية القائمة تُملأ من رؤوس وحداتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('branch_unit_id')->nullable()->after('organization_unit_id')->constrained('organization_units')->nullOnDelete();
            $table->foreignId('handling_override_by_id')->nullable()->after('handling_unit_name')->constrained('users')->nullOnDelete();
            $table->timestamp('handling_override_at')->nullable()->after('handling_override_by_id');
            $table->foreignId('handling_override_approved_by_id')->nullable()->after('handling_override_at')->constrained('users')->nullOnDelete();
            $table->timestamp('handling_override_approved_at')->nullable()->after('handling_override_approved_by_id');
        });

        // النسخ القائمة: الفرع = رأس وحدة التفعيل (الجذر أو وحدة نوعها «فرع»)
        $units = DB::table('organization_units')->get(['id', 'parent_id', 'unit_type'])->keyBy('id');
        $headOf = function (int $id) use ($units): ?int {
            for ($u = $units->get($id), $n = 0; $u && $n < 12; $n++) {
                if ($u->parent_id === null || $u->unit_type === 'region') return (int) $u->id;
                $u = $units->get($u->parent_id);
            }
            return null;
        };
        foreach (DB::table('risks')->where('risk_type', 'active')->whereNotNull('organization_unit_id')->get(['id', 'organization_unit_id']) as $r) {
            if ($h = $headOf((int) $r->organization_unit_id)) DB::table('risks')->where('id', $r->id)->update(['branch_unit_id' => $h]);
        }
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handling_override_approved_by_id');
            $table->dropConstrainedForeignId('handling_override_by_id');
            $table->dropConstrainedForeignId('branch_unit_id');
            $table->dropColumn(['handling_override_at', 'handling_override_approved_at']);
        });
    }
};
