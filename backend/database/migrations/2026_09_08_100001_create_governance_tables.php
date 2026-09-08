<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١ — الحوكمة (BACKEND.md ٥-١): الأماكن التسعة، الهيكل التنظيمي، ملف المستخدم (الدور)،
 * سجل التدقيق، صندوق الإشعارات. منقولة من OHSMS بلا tenant_id وبلا سياق المشاريع.
 *
 * - user_profiles هو مصدر الدور (كما في OHSMS: الدور يُحسم من user_profiles.role نصاً).
 *   أعمدة المرحلة ٠ في users (role, dept_code, is_active) تُنقل إليه ثم تُحذف.
 * - app_notifications بدل notifications لتفادي التعارض مع جدول Laravel Notifiable.
 * - unit_type و role نصوص لا enum (Postgres يصعّب تعديل enum لاحقاً — خطأ OHSMS المعروف).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();      // HZ-00 … HZ-08
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();      // معرّف الوحدة كما في ipa-depts (gm, adm-eng, …)
            $table->foreignId('parent_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('unit_type', 20)->default('department'); // company | region | branch | department | section | team
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete(); // المكان الذي تشغله
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_name', 200)->nullable(); // اسم مدير الإدارة إن لم يكن له حساب بعد
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('role', 40);                 // مفتاح من PermissionRegistry::ROLES
            $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('model_name', 100);
            $table->unsignedBigInteger('object_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['model_name', 'object_id']);
            $table->index('created_at');
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'is_read']);
        });

        // نقل أدوار المرحلة ٠ من users إلى user_profiles
        $map = [
            'safety' => 'system_admin', 'tech' => 'field_worker', 'fm' => 'facilities_manager',
            'adm' => 'admin_eng_manager', 'exec' => 'top_management', 'cons' => 'consultant_office', 'dept' => 'department_manager',
        ];
        foreach (Illuminate\Support\Facades\DB::table('users')->get() as $u) {
            $role = $map[$u->role ?? ''] ?? ($u->role ?: 'employee');
            Illuminate\Support\Facades\DB::table('user_profiles')->insert([
                'user_id' => $u->id, 'role' => $role, 'is_active' => $u->is_active ?? true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'dept_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->nullable();
            $table->string('dept_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('places');
    }
};
