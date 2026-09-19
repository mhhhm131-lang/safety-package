<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٠-٤-ب (قرار ٥٢): قاعدة الاعتماد — مسؤول السلامة مدير النظام، ولا يعمل حساب ولا تُمنح صلاحية إلا بعد اعتماده.
 * ما يسجله غيره (مدير المرافق، المناوب) يبقى «بانتظار الاعتماد» (is_active = false + pending_since) حتى «اعتمد» أو «أعِده».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestamp('pending_since')->nullable()->after('is_active');
            $table->foreignId('pending_by_id')->nullable()->after('pending_since')->constrained('users')->nullOnDelete();
            $table->string('pending_note', 200)->nullable()->after('pending_by_id');   // ما الذي ينتظر: حساب جديد / تغيير الدور / التغطية
            $table->foreignId('approved_by_id')->nullable()->after('pending_note')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->string('return_note', 200)->nullable()->after('approved_at');     // سبب «أعِده»
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_by_id');
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn(['pending_since', 'pending_note', 'approved_at', 'return_note']);
        });
    }
};
