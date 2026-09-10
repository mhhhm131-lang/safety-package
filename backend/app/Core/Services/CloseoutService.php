<?php

namespace App\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * الإغلاق (المرحلة ٨-١): تسليم منظومة بلا بيانات تجربة، ومرجعياتها كاملة.
 *
 * **الحدّ الفاصل** (BACKEND.md ٨-١ بند ٦): يُحذف العمل التشغيلي — ما أنشأته بوابات المراحل
 * وما سينشئه التشغيل اليومي — ويبقى المرجعي الذي بُذر أو أُدخل: كتاب المخاطر والسجل العام،
 * الأماكن التسعة، الهيكل، أنواع التصاريح وقواعدها، المبنى وجهات الاتصال، المهن، الحسابات.
 *
 * **لماذا بالجدول لا بعلامة نصية:** بوابات المراحل تركت بيانات بأوسمة مختلفة
 * («بوابة ٣ —»، «بوابة٦ب-HHMM»، رموز `ش-`/`ت-`/`FL-9`)، ومطابقة النصوص تُخطئ في الاتجاهين:
 * تترك ما لا وسم له وتحذف ما يشبهه. القائمتان صريحتان، و`assertAllTablesClassified()`
 * يمنع أن يمرّ جدول وحدة جديدة بلا قرار.
 */
class CloseoutService
{
    /**
     * الجداول التشغيلية بترتيب حذف يحترم المفاتيح الأجنبية (الأبناء قبل الآباء).
     * المخاطر الفعّالة تُعالَج على حدة في `purgeActiveRisks()`.
     */
    public const OPERATIONAL = [
        // بلاغات الشاغل
        'incident_attachments', 'incident_events', 'incident_risks', 'incidents',
        // الطوارئ
        'aar_corrective_actions', 'after_action_reports',
        'drill_participants', 'evacuation_drills', 'evacuation_check_ins',
        'emergency_event_logs', 'emergency_notifications',
        'emergency_message_responses', 'emergency_mass_messages',
        'emergency_medical_profiles', 'emergency_visitors',
        'wearable_alerts', 'emergency_wearables',
        'panic_alert_responders', 'panic_alerts', 'lockdowns',
        'emergency_cameras', 'emergency_equipment_inspections', 'emergency_equipment',
        'emergency_team_members', 'emergency_teams',
        'emergency_incident_steps', 'emergency_incidents',
        // إنترنت الأشياء
        'iot_events', 'iot_devices',
        // التصاريح والمعدات
        'gate_logs',
        'permit_attachments', 'permit_deviations', 'permit_events', 'permit_requirements',
        'permit_risks', 'permit_trades', 'permit_workers', 'permits',
        'equipment_inspections', 'equipment',
        // النماذج الرقمية
        'form_answers', 'form_submissions', 'form_assignments',
        'form_fields', 'form_template_risks', 'form_templates',
        // المشاريع والمقاولون والعمال
        'manhour_logs', 'competency_gaps',
        'worker_documents', 'worker_status_events', 'worker_training_records', 'workers',
        'project_contractor_events', 'project_contractors', 'projects',
        'contractor_verifications', 'contractor_profiles',
        'external_party_documents', 'external_party_evaluations', 'external_party_risks',
        'external_parties',
        // أثر التشغيل
        'app_notifications', 'audit_logs',
    ];

    /** المرجعي: يبقى كما هو. */
    public const REFERENCE = [
        // الحوكمة والحسابات
        'users', 'user_profiles', 'organization_units', 'places', 'settings', 'institute_documents',
        // المخاطر: الكتاب والسجل العام وتصنيفاتهما (الفعّالة تُحذف على حدة)
        'risks', 'risk_categories', 'risk_sub_categories', 'risk_causes', 'risk_controls',
        'risk_phases', 'risk_events', 'risk_notes',
        'risk_phase_affected_groups', 'risk_phase_affected_group_details', 'risk_phase_causes',
        'affected_groups', 'risk_required_permit_types',
        // أنواع التصاريح وقواعدها
        'permit_types', 'permit_type_conflict_rules', 'permit_type_trades',
        'qualification_checklist_items',
        // المهن والكفاءات
        'trades', 'trade_competencies', 'trade_risk_categories', 'trade_tasks',
        'training_topics', 'competency_requirements',
        // المبنى وجهات الاتصال والقوالب
        'emergency_buildings', 'building_floors', 'building_exits', 'assembly_points',
        'emergency_contacts', 'emergency_message_templates',
        // خطط الاستجابة المشتقة من الوثائق الثماني (المرحلة ١٠-١): تُعاد قراءتها من الوثيقة، لا بيانات تشغيلية فيها
        'response_plans', 'response_plan_steps',
        // قنوات التحقق من المقاولين (إعدادات لا بيانات)
        'contractor_channels',
        // بنية الإطار
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens', 'sqlite_sequence',
    ];

    /** أسماء الدخول التجريبية (بذرة التطوير + ما أنشأته البوابات). */
    public const DEMO_USERNAMES = [
        'salama', 'coord', 'fani', 'mudir', 'marafiq', 'shuon', 'idara', 'maktab',
        'test.gate', 'g6.muqawil',
    ];

    /**
     * كلمة المرور المبذورة في `DemoUsersSeeder`.
     *
     * **الخطر في الكلمة لا في الاسم** (تصحيح ٢٠٢٦-٠٩-٠٩): الاسم التجريبي الذي غُيّرت كلمته
     * صار حساباً حقيقياً يعمل به صاحبه، وتعطيله يقفل الباب عليه. والاسم الذي بقي على
     * الكلمة المبذورة خطرٌ مهما كان اسمه — الموقع مفتوح للإنترنت.
     */
    public const SEEDED_PASSWORD = '1234';

    /** هل ما زال الحساب على كلمة المرور المبذورة؟ */
    public function stillSeeded(User $user): bool
    {
        return Hash::check(self::SEEDED_PASSWORD, (string) $user->password);
    }

    /**
     * جرد الحسابات في **مرور واحد**: الاسم والدور والحالة وهل كلمته مبذورة.
     *
     * **لماذا مرور واحد:** `Hash::check` عملية bcrypt متعمَّدة البطء (ربع ثانية لكل حساب
     * على الخطة المجانية). فحص الحسابات مرتين — مرة للعدّ ومرة للعرض — أوقع الصفحة في
     * انقطاع الاتصال على المنشور. والملف يُحمَّل مع الحساب بدل استعلام لكل صف.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, username: string, name: string, role: string, active: bool, seeded: bool}>
     */
    public function accountAudit()
    {
        return User::query()
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->orderBy('users.username')
            ->get(['users.id', 'users.username', 'users.name', 'users.password',
                'user_profiles.role', 'user_profiles.is_active'])
            ->map(fn ($row) => [
                'id'       => (int) $row->id,
                'username' => (string) $row->username,
                'name'     => (string) $row->name,
                'role'     => $row->role ?? '—',
                'active'   => (bool) $row->is_active,
                'seeded'   => Hash::check(self::SEEDED_PASSWORD, (string) $row->password),
            ]);
    }

    /**
     * الحسابات الخطرة: **كل** حساب ما زال على الكلمة المبذورة، مهما كان اسمه.
     *
     * **لماذا كل الحسابات لا القائمة التجريبية وحدها** (تصحيح ٢٠٢٦-٠٩-٠٩): المستخدم أعاد
     * تسمية حساب مبذور، فخرج من القائمة وبقي خطره. الاسم يتغيّر والكلمة هي الخطر.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function riskyAccounts()
    {
        return User::query()->get(['id', 'username', 'name', 'password'])
            ->filter(fn (User $u) => $this->stillSeeded($u))
            ->values();
    }

    /**
     * جرد ما سيُحذف: [الاسم المعروض => العدد]. يُعرض قبل الحذف دائماً.
     *
     * @return array<string, int>
     */
    public function inventory(): array
    {
        $groups = [
            'بلاغات الشاغل'        => ['incidents'],
            'الحالات الطارئة'      => ['emergency_incidents'],
            'التمارين'             => ['evacuation_drills'],
            'الأجهزة الموصولة'     => ['iot_devices'],
            'التصاريح'             => ['permits'],
            'المعدات'              => ['equipment'],
            'النماذج الرقمية'      => ['form_templates'],
            'تكليفات النماذج'      => ['form_assignments'],
            'تعبئات النماذج'       => ['form_submissions'],
            'الأطراف الخارجية'     => ['external_parties'],
            'المشاريع'             => ['projects'],
            'العمال'               => ['workers'],
            'الإشعارات'            => ['app_notifications'],
            'سجل التدقيق'          => ['audit_logs'],
        ];

        $out = [];
        foreach ($groups as $label => $tables) {
            $n = 0;
            foreach ($tables as $table) {
                $n += Schema::hasTable($table) ? DB::table($table)->count() : 0;
            }
            $out[$label] = $n;
        }

        $out['المخاطر الفعّالة (الإدارات والأماكن)'] = $this->activeRiskIds()->count();

        return $out;
    }

    /** ما يبقى — يُعرض بجانب الجرد حتى يرى المستخدم أن المرجعي محفوظ. */
    public function preserved(): array
    {
        return [
            'السجل العام للمعهد (كتاب المعهد)' => DB::table('risks')->where('risk_type', 'reference')->count(), // قرار ٢١: طبقة واحدة
            'الأماكن'             => DB::table('places')->count(),
            'الوحدات التنظيمية'   => DB::table('organization_units')->count(),
            'أنواع التصاريح'      => DB::table('permit_types')->count(),
            'بنود التأهيل'        => DB::table('qualification_checklist_items')->count(),
            'المهن'               => DB::table('trades')->count(),
            'جهات الاتصال'        => DB::table('emergency_contacts')->count(),
            'الحسابات'            => DB::table('users')->count(),
        ];
    }

    /** حال كتاب المعهد كما هو في القاعدة الآن (للشاشة وللبوابة). */
    public function bookStatus(): array
    {
        $codes = DB::table('risks')->where('risk_type', 'reference')->pluck('code');
        $institute = $codes->filter(fn ($c) => (bool) preg_match('/^(PH|CH|BI|ME|EL|FI|ER|OR)-\d\d-\d\d$/', (string) $c))->count();
        return [
            'الأصناف الرئيسية'        => DB::table('risk_categories')->count(),
            'الفروع'                   => DB::table('risk_sub_categories')->count(),
            'مخاطر السجل العام'        => $codes->count(),
            'منها من كتاب المعهد'      => $institute,
            'منها بأكواد أخرى (OHSMS أو مضافة يدوياً)'    => $codes->count() - $institute,
            'مخاطر فعلية (إدارات)'     => DB::table('risks')->where('risk_type', 'active')->count(),
        ];
    }

    /**
     * ما يمنع استبدال الكتاب: عمل تشغيلي يشير إلى مخاطر قائمة. يُحذف أولاً بـ purge().
     *
     * @return array<string, int>
     */
    public function bookReplaceBlockers(): array
    {
        return array_filter([
            'مخاطر فعلية للإدارات' => DB::table('risks')->where('risk_type', 'active')->count(),
            'بلاغات مربوطة بخطر'   => DB::table('incidents')->count(),
            'مخاطر على تصاريح'     => DB::table('permit_risks')->count(),
            'نماذج مولَّدة من خطر' => DB::table('form_templates')->whereNotNull('source_risk_id')->count(),
        ]);
    }

    /**
     * استبدال كتاب المعهد (المرحلة ٩، قرار ٢٢): يحذف شجرة المخاطر كلها (الأصناف والفروع والأخطار
     * وطبقاتها وبنود التحكم وقواعد التصاريح) ثم يبذر الكتاب الجديد من `institute_risk_book.json`
     * مع بنود التحكم وقواعد التصاريح على الأصناف الجديدة. المتأثرون والأماكن والهيكل لا تُمس.
     *
     * @return array<string, int> حال الكتاب بعد الاستبدال
     */
    public function replaceBook(): array
    {
        if ($blockers = $this->bookReplaceBlockers()) {
            throw new \RuntimeException('لا يُستبدل الكتاب وهناك عمل تشغيلي مربوط به: '
                .implode('، ', array_map(fn ($k, $v) => "$k ($v)", array_keys($blockers), $blockers))
                .'. احذف بيانات التجربة أولاً.');
        }
        DB::transaction(function () {
            foreach (['risk_required_permit_types', 'risk_controls', 'risks', 'risk_causes', 'risk_sub_categories', 'risk_categories'] as $t) {
                DB::table($t)->delete();
            }
            (new \Database\Seeders\RiskBookSeeder)->run();
            (new \Database\Seeders\RiskControlsSeeder)->run();
            (new \Database\Seeders\RiskRequiredPermitTypesSeeder)->run();
        });
        return $this->bookStatus();
    }

    /**
     * الحذف الفعلي. يعيد [الجدول => عدد المحذوف] لما حُذف منه شيء.
     *
     * @return array<string, int>
     */
    public function purge(): array
    {
        $deleted = [];

        DB::transaction(function () use (&$deleted) {
            $deleted = array_merge($deleted, $this->purgeActiveRisks());

            foreach (self::OPERATIONAL as $table) {
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $n = DB::table($table)->count();
                if ($n > 0) {
                    DB::table($table)->delete();
                    $deleted[$table] = $n;
                }
            }
        });

        return $deleted;
    }

    /**
     * المخاطر الفعّالة وأبناؤها. الكتاب والسجل العام لا يُمسّان،
     * ولا التصنيفات ولا بنود التحكم على مستوى الفئة (`risk_id` فيها فارغ).
     *
     * @return array<string, int>
     */
    private function purgeActiveRisks(): array
    {
        $ids = $this->activeRiskIds()->all();
        if (!$ids) {
            return [];
        }

        $out = [];
        $phaseIds = DB::table('risk_phases')->whereIn('risk_id', $ids)->pluck('id')->all();

        $children = [
            'risk_phase_affected_group_details' => ['risk_phase_id', $phaseIds],
            'risk_phase_affected_groups'        => ['risk_phase_id', $phaseIds],
            'risk_phase_causes'                 => ['risk_phase_id', $phaseIds],
            'risk_phases'                       => ['risk_id', $ids],
            'risk_controls'                     => ['risk_id', $ids],
            'risk_events'                       => ['risk_id', $ids],
            'risk_notes'                        => ['risk_id', $ids],
        ];

        foreach ($children as $table => [$column, $values]) {
            if (!Schema::hasTable($table) || !$values) {
                continue;
            }
            $n = DB::table($table)->whereIn($column, $values)->delete();
            if ($n) {
                $out[$table] = $n;
            }
        }

        $out['risks (الفعّالة)'] = DB::table('risks')->whereIn('id', $ids)->delete();

        return $out;
    }

    private function activeRiskIds()
    {
        return DB::table('risks')->where('risk_type', 'active')->pluck('id');
    }

    /**
     * حارس: لا يمرّ جدول بلا قرار. لو أضافت وحدة جديدة جدولاً ولم يُصنَّف،
     * ظهر هنا بدل أن يبقى في القاعدة بعد التسليم أو يُحذف بلا قصد.
     *
     * @return array<string> الجداول غير المصنَّفة
     */
    public function unclassifiedTables(): array
    {
        $known = array_merge(self::OPERATIONAL, self::REFERENCE);

        $all = match (DB::connection()->getDriverName()) {
            'sqlite' => collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))->pluck('name'),
            'pgsql'  => collect(DB::select("SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public'"))->pluck('name'),
            default  => collect(),
        };

        return $all->reject(fn ($t) => in_array($t, $known, true) || str_starts_with($t, 'sqlite_'))
            ->values()->all();
    }
}
