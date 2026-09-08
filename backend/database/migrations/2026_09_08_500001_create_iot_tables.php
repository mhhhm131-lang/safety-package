<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٥ — إنترنت الأشياء (Integration من OHSMS + أجهزة Emergency).
 *
 * إعدادات الأجهزة كانت في OHSMS أعمدة في tenant_settings (٢٤ عموداً لخمسة أنظمة) وبلا مفتاح توقيع؛ هنا جدول `iot_devices`:
 * جهاز لكل نظام (لوحة الحريق، الأبواب، المصاعد، التكييف، الشاشات، وسيط MQTT) ببروتوكوله وعنوانه ومفتاح توقيعه الخاص
 * (الإصلاح المقرر ٥-٤: «توقيع HMAC أو مفتاح لكل جهاز»). `iot_events` سجل ما وصل من الأجهزة (Webhook/MQTT) وما فُعل به.
 * `emergency_buildings.fire_zones` العمود المفقود في OHSMS. الكاميرات والأساور من ترحيل OHSMS الداخلي بلا tenant.
 * لم يُنقل: ble_beacons/person_positions (تحديد المواقع الداخلية مؤجل — ٥-٤).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_devices', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // fire_panel|access_control|elevator|hvac|signage|mqtt_broker|other
            $table->string('name', 120);
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('protocol', 10)->default('rest'); // rest|bacnet|modbus|mqtt|webhook
            $table->string('host', 255)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('base_path', 255)->nullable();
            $table->string('scheme', 5)->default('http'); // http|https
            $table->unsignedInteger('unit_id')->nullable(); // Modbus unit / BACnet device id
            $table->string('username', 120)->nullable();
            $table->text('password')->nullable(); // مشفّر
            $table->text('webhook_secret')->nullable(); // مشفّر — HMAC-SHA256 لكل جهاز
            $table->json('config')->nullable(); // خريطة المناطق ← الأماكن، عناوين BACnet/Modbus، مواضيع MQTT…
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('last_status')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'is_enabled']);
        });

        Schema::create('iot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('iot_devices')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('event_type', 40);
            $table->string('source', 10)->default('webhook'); // webhook|mqtt|poll
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('source_ip', 45)->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('emergency_incidents')->nullOnDelete();
            $table->string('action_taken', 40)->nullable(); // incident_created|logged_to_incident|notified|ignored|rejected
            $table->text('note')->nullable();
            $table->timestamp('received_at');
            $table->index(['device_id', 'received_at']);
            $table->index('event_type');
        });

        Schema::table('emergency_buildings', function (Blueprint $table) {
            $table->unsignedSmallInteger('fire_zones')->nullable()->after('basement_floors');
        });

        Schema::create('emergency_cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('emergency_buildings')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('name', 100);
            $table->string('camera_id', 50)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('stream_url', 500)->nullable();
            $table->string('snapshot_url', 500)->nullable();
            $table->string('type', 10)->default('fixed'); // fixed|ptz|dome|thermal
            $table->string('status', 15)->default('online'); // online|offline|maintenance
            $table->boolean('is_emergency_priority')->default(false);
            $table->boolean('has_audio')->default(false);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('floor_number')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('emergency_wearables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_id', 100)->unique();
            $table->string('device_type', 50)->default('smartwatch');
            $table->string('device_model', 100)->nullable();
            $table->string('push_token', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('panic_enabled')->default(true);
            $table->boolean('fall_detection_enabled')->default(false);
            $table->boolean('heart_rate_alert_enabled')->default(false);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->decimal('last_latitude', 10, 8)->nullable();
            $table->decimal('last_longitude', 11, 8)->nullable();
            $table->integer('last_heart_rate')->nullable();
            $table->integer('battery_level')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('wearable_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wearable_id')->constrained('emergency_wearables')->cascadeOnDelete();
            $table->string('alert_type', 15); // panic|fall|heart_rate|low_battery|sos
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('heart_rate')->nullable();
            $table->text('additional_data')->nullable();
            $table->string('status', 15)->default('triggered'); // triggered|acknowledged|resolved|false_alarm
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['wearable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wearable_alerts');
        Schema::dropIfExists('emergency_wearables');
        Schema::dropIfExists('emergency_cameras');
        Schema::table('emergency_buildings', fn (Blueprint $t) => $t->dropColumn('fire_zones'));
        Schema::dropIfExists('iot_events');
        Schema::dropIfExists('iot_devices');
    }
};
