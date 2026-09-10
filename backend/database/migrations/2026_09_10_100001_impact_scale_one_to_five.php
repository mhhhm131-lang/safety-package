<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** قرار ٢٧ (المرحلة ٩): أثر الفئة المتأثرة بمقياس ١–٥ بدل low/medium/high/critical. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['low' => '2', 'medium' => '3', 'high' => '4', 'critical' => '5'] as $old => $new) {
            DB::table('risk_phase_affected_group_details')->where('impact', $old)->update(['impact' => $new]);
        }
    }

    public function down(): void
    {
        foreach (['2' => 'low', '3' => 'medium', '4' => 'high', '5' => 'critical'] as $new => $old) {
            DB::table('risk_phase_affected_group_details')->where('impact', $new)->update(['impact' => $old]);
        }
    }
};
