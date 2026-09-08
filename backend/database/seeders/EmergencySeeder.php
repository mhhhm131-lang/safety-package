<?php

namespace Database\Seeders;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyContact;
use App\Modules\Emergency\Models\EmergencyMessageTemplate;
use Illuminate\Database\Seeder;

/**
 * بذرة الطوارئ (المرحلة ٤، الخطوة ٤) — آمنة على الإنتاج (لا تكتب فوق تعديلات المستخدم).
 *
 * المبنى: مبنى المعهد الواحد («مبنى الملز» كما في تذييل خطط الاستجابة). الطوابق والمخارج ونقاط التجمع **لا تُبذر**:
 * الفجوتان ٣ و٦ في BACKEND.md بيد المستخدم/المرافق وتُدخل من شاشة المبنى — لا أرقام مخترعة.
 * جهات الاتصال الخارجية من خطط الاستجابة (خطوة «إبلاغ الدفاع المدني والهلال والشرطة») بأرقام الطوارئ الرسمية في المملكة:
 * الدفاع المدني ٩٩٨، الهلال الأحمر ٩٩٧، الشرطة ٩٩٩، والرقم الموحد ٩١١ (منطقة الرياض).
 * القوالب: قوالب OHSMS العشرة (كانت داخل الترحيل هناك).
 */
class EmergencySeeder extends Seeder
{
    public function run(): void
    {
        $building = EmergencyBuilding::firstOrCreate(['code' => 'IPA-MAIN'], [
            'name' => 'مبنى معهد الإدارة العامة — الملز',
            'name_en' => 'IPA Main Building — Malaz',
            'building_type' => 'government',
            'floors_count' => 1,
            'basement_floors' => 0,
            'status' => 'active',
            'risk_level' => 'medium',
            'emergency_status' => 'normal',
            'address' => 'الرياض — الملز',
        ]);

        $contacts = [
            ['name' => 'الدفاع المدني', 'phone' => '998', 'contact_type' => 'external', 'role' => 'حريق وإنقاذ', 'organization' => 'المديرية العامة للدفاع المدني', 'priority' => 1, 'auto_notify' => true],
            ['name' => 'الهلال الأحمر السعودي', 'phone' => '997', 'contact_type' => 'external', 'role' => 'إسعاف', 'organization' => 'هيئة الهلال الأحمر السعودي', 'priority' => 2, 'auto_notify' => true],
            ['name' => 'الشرطة', 'phone' => '999', 'contact_type' => 'external', 'role' => 'أمن', 'organization' => 'الأمن العام', 'priority' => 3, 'auto_notify' => true],
            ['name' => 'الرقم الموحد للطوارئ', 'phone' => '911', 'contact_type' => 'external', 'role' => 'كل الطوارئ (منطقة الرياض)', 'organization' => 'المركز الوطني للعمليات الأمنية', 'priority' => 4, 'auto_notify' => false],
        ];
        foreach ($contacts as $c) {
            EmergencyContact::firstOrCreate(['phone' => $c['phone'], 'contact_type' => 'external'], $c + ['building_id' => $building->id, 'is_active' => true]);
        }

        $templates = [
            ['code' => 'FIRE_ALERT', 'name' => 'تنبيه حريق', 'category' => 'fire', 'title_ar' => 'تنبيه حريق', 'title_en' => 'Fire Alert', 'message_ar' => 'تم اكتشاف حريق في {{building_name}}. الرجاء الإخلاء فوراً إلى {{assembly_point}}.', 'message_en' => 'Fire detected in {{building_name}}. Please evacuate immediately to {{assembly_point}}.', 'variables' => ['building_name', 'assembly_point'], 'severity' => 'critical'],
            ['code' => 'EARTHQUAKE', 'name' => 'تنبيه زلزال', 'category' => 'earthquake', 'title_ar' => 'تنبيه زلزال', 'title_en' => 'Earthquake Alert', 'message_ar' => 'تم رصد هزة أرضية. احتمِ تحت الطاولات وابتعد عن النوافذ. لا تستخدم المصاعد.', 'message_en' => 'Earthquake detected. Take cover and stay away from windows. Do not use elevators.', 'variables' => [], 'severity' => 'critical'],
            ['code' => 'EVACUATION', 'name' => 'أمر إخلاء', 'category' => 'evacuation', 'title_ar' => 'أمر إخلاء', 'title_en' => 'Evacuation Order', 'message_ar' => 'الرجاء إخلاء {{building_name}} فوراً والتوجه إلى {{assembly_point}}.', 'message_en' => 'Please evacuate {{building_name}} immediately and proceed to {{assembly_point}}.', 'variables' => ['building_name', 'assembly_point'], 'severity' => 'critical'],
            ['code' => 'ALL_CLEAR', 'name' => 'انتهاء الخطر', 'category' => 'all_clear', 'title_ar' => 'انتهاء الخطر', 'title_en' => 'All Clear', 'message_ar' => 'تم إعلان انتهاء حالة الطوارئ. يمكنكم العودة للمبنى بأمان.', 'message_en' => 'The emergency has ended. You may safely return to the building.', 'variables' => [], 'severity' => 'low'],
            ['code' => 'DRILL_START', 'name' => 'بدء تمرين', 'category' => 'drill', 'title_ar' => 'تمرين إخلاء', 'title_en' => 'Evacuation Drill', 'message_ar' => 'هذا تمرين إخلاء. الرجاء التعامل معه بجدية والتوجه لنقاط التجمع.', 'message_en' => 'This is an evacuation drill. Please treat it seriously and proceed to assembly points.', 'variables' => [], 'severity' => 'medium'],
            ['code' => 'DRILL_END', 'name' => 'انتهاء تمرين', 'category' => 'drill', 'title_ar' => 'انتهاء التمرين', 'title_en' => 'Drill Complete', 'message_ar' => 'انتهى تمرين الإخلاء. شكراً لتعاونكم.', 'message_en' => 'The evacuation drill has ended. Thank you.', 'variables' => [], 'severity' => 'low'],
            ['code' => 'SECURITY_THREAT', 'name' => 'تهديد أمني', 'category' => 'security', 'title_ar' => 'تنبيه أمني', 'title_en' => 'Security Alert', 'message_ar' => 'تم الإبلاغ عن تهديد أمني. الرجاء البقاء في أماكنكم وإغلاق الأبواب.', 'message_en' => 'A security threat has been reported. Please stay in place and secure all doors.', 'variables' => [], 'severity' => 'critical'],
            ['code' => 'WEATHER_WARNING', 'name' => 'تحذير طقس', 'category' => 'weather', 'title_ar' => 'تحذير طقس', 'title_en' => 'Weather Warning', 'message_ar' => 'تحذير من {{weather_type}}. الرجاء اتخاذ الاحتياطات اللازمة.', 'message_en' => 'Warning: {{weather_type}}. Please take necessary precautions.', 'variables' => ['weather_type'], 'severity' => 'high'],
            ['code' => 'MEDICAL_EMERGENCY', 'name' => 'طوارئ طبية', 'category' => 'medical', 'title_ar' => 'طوارئ طبية', 'title_en' => 'Medical Emergency', 'message_ar' => 'حالة طوارئ طبية في {{location}}. فريق الإسعاف في الطريق.', 'message_en' => 'Medical emergency at {{location}}. Medical team is en route.', 'variables' => ['location'], 'severity' => 'high'],
            ['code' => 'CHEMICAL_SPILL', 'name' => 'تسرب كيميائي', 'category' => 'chemical', 'title_ar' => 'تسرب كيميائي', 'title_en' => 'Chemical Spill', 'message_ar' => 'تم اكتشاف تسرب كيميائي في {{location}}. ابتعد عن المنطقة فوراً وغطِّ أنفك وفمك.', 'message_en' => 'Chemical spill detected at {{location}}. Move away immediately.', 'variables' => ['location'], 'severity' => 'critical'],
        ];
        foreach ($templates as $t) {
            EmergencyMessageTemplate::firstOrCreate(['code' => $t['code']], $t + ['default_channels' => ['app', 'email'], 'is_builtin' => true, 'is_active' => true, 'sort_order' => 0]);
        }
    }
}
