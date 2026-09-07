<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطبقة صفر — المخزن المركزي لبيانات المعهد (BACKEND.md ٤-٢).
 * صف لكل مفتاح من مفاتيح localStorage الحالية (ipa-*).
 *
 * `data` نص JSON كما كتبه المتصفح حرفياً. لا يُحلَّل في الخادم عمداً:
 * تحليله بـ PHP يحوّل الكائن الفارغ {} إلى مصفوفة [] فتتكسر بنى الواجهة (levels وغيرها).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institute_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->longText('data');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institute_documents');
    }
};
