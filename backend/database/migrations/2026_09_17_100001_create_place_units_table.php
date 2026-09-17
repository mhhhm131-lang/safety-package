<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١٨-٣ (قرار ٤٧): وحدات الأماكن — كل مكان له وحدات بنوع وصفات قليلة يُدخلها صاحبه مرة واحدة
 * (قاعة برقم ودور وسعة، غرفة كهرباء، مستودع، مطعم بمشغّله، إدارة بدورها وموقعها…).
 * الإدارة في المكاتب هي وحدة الهيكل نفسها (organization_unit_id) لا تُكرَّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('name', 120);
            $table->string('floor', 60)->nullable();
            $table->string('location', 200)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('operator', 120)->nullable();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['place_id', 'type']);
            $table->unique(['place_id', 'type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_units');
    }
};
