<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** المرحلة ٢١-٢ (قرار ٥٤): حالة «وضع التجربة» — خط الأساس لكل جدول ونسخة ملفات المعهد والإعدادات قبل التجربة. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_state', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);   // baseline | snapshot
            $table->string('name', 120);  // اسم الجدول
            $table->longText('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_state');
    }
};
