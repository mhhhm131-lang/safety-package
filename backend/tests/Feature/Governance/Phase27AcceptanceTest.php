<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ٢٧-د قبول المرحلة ٢٧ «ما ينتظرك يكتمل» (قرار ٦٧): الحالات الـ٣٧ التي كانت بلا بطاقة في جرد ٢٠٢٦-٠٩-٢٩
 * (`docs/inventory-two-parts-2026-09-29.md` البند ١-١٠) — كل واحدة لها اليوم مصير مكتوب ومحروس:
 *   ٣١ صارت بطاقة: مصدرها مسجَّل في InboxService، ومفتاحها في المصدر، واختبار باسمه يحرس «تظهر لصاحبها وحده ← الفعل ← تختفي».
 *   ٣  ليست حالة انتظار (القراءة خالفت الجرد): لا بطاقة تَعِد بما لا مسار له.
 *   ٣  موعد بلا شاشة تسجّله: مؤجلة بانتظار قرار المستخدم — وهذا الاختبار يسقط يوم تُبنى الشاشة ليذكّر ببطاقتها.
 * البرهان على الشاشة: `tests/gates/webkit-27a.py` و`27b` و`27c` على المحلي.
 */
class Phase27AcceptanceTest extends TestCase
{
    /** أرقام الجرد التي كانت ✗ */
    private const GAPS = [3, 4, 22, 24, 25, 27, 28, 30, 34, 35, 36, 37, 38, 39, 40, 43, 46, 47, 49, 52, 53, 54, 55, 56, 57, 58, 59, 61, 63, 65, 66, 67, 68, 69, 70, 73, 74];

    private const COMMAND = \App\Modules\Emergency\Inbox\EmergencyCommandTasks::class;
    private const PERMIT = \App\Modules\Permit\Inbox\PermitTasks::class;
    private const RISK = \App\Modules\Risk\Inbox\RiskTasks::class;
    private const FORM = \App\Modules\Form\Inbox\FormTasks::class;
    private const CONTRACTOR = \App\Modules\Project\Inbox\ContractorTasks::class;
    private const ACCOUNT = \App\Modules\Governance\Inbox\AccountApprovalTasks::class;
    private const READINESS = \App\Modules\Governance\Inbox\ReadinessTasks::class;
    private const DEADLINE = \App\Modules\Governance\Inbox\DeadlineTasks::class;

    /** رقم الجرد => [المصدر، جزء المفتاح كما هو في المصدر، ملف الاختبار، الاختبار] */
    private const CARDS = [
        // ٢٧-أ
        22 => [self::COMMAND, 'emsgfu:', 'Emergency/CommandCardsTest', 'test_unanswered_mass_message_asks_the_center_to_remind_once'],
        24 => [self::COMMAND, 'eack:', 'Emergency/CommandCardsTest', 'test_unacknowledged_case_asks_the_center_and_the_places_team_until_one_acknowledges'],
        25 => [self::COMMAND, 'ectl:', 'Emergency/CommandCardsTest', 'test_open_case_asks_the_commander_and_the_center_to_contain_then_end'],
        27 => [self::COMMAND, 'emuster:', 'Emergency/CommandCardsTest', 'test_unaccounted_people_ask_the_center_until_everyone_is_accounted_for'],
        28 => [self::COMMAND, 'elock:', 'Emergency/CommandCardsTest', 'test_active_lockdown_asks_the_center_to_lift_it_and_replaces_the_control_card'],
        30 => [self::COMMAND, 'aarreview:', 'Emergency/CommandCardsTest', 'test_report_under_review_asks_the_center_to_approve_then_publish'],
        // ٢٧-ب
        38 => [self::READINESS, "'emplans'", 'Governance/ReadinessCardsTest', 'test_places_without_a_response_plan_ask_the_center_to_sync'],
        43 => [self::READINESS, 'eventteam:', 'Governance/ReadinessCardsTest', 'test_nominated_event_team_asks_the_security_head_and_one_press_approves'],
        46 => [self::RISK, ':rejected', 'Governance/DecisionCardsTest', 'test_draft_and_rejected_risk_ask_their_author'],
        47 => [self::RISK, ':draft', 'Governance/DecisionCardsTest', 'test_draft_and_rejected_risk_ask_their_author'],
        49 => [self::FORM, 'formremind:', 'Governance/DecisionCardsTest', 'test_overdue_form_asks_its_sender_to_remind_once'],
        52 => [self::PERMIT, ':activate', 'Permit/PermitDecisionCardsTest', 'test_approved_permit_asks_those_who_activate_and_tells_what_is_left'],
        53 => [self::PERMIT, ':reqs', 'Permit/PermitDecisionCardsTest', 'test_open_requirements_ask_the_requesters_party_until_fulfilled'],
        54 => [self::PERMIT, ':deviations', 'Permit/PermitDecisionCardsTest', 'test_open_deviation_asks_those_who_supervise_until_resolved'],
        56 => [self::PERMIT, ':evaluate', 'Permit/PermitDecisionCardsTest', 'test_completed_permit_asks_the_reviewers_to_evaluate'],
        58 => [self::PERMIT, ':draft', 'Permit/PermitDecisionCardsTest', 'test_draft_asks_its_requester_and_one_press_submits_it'],
        61 => [self::CONTRACTOR, 'worker:{$w->id}:{$w->status}', 'Governance/DecisionCardsTest', 'test_worker_in_induction_or_training_asks_those_who_approve_workers'],
        69 => [self::CONTRACTOR, ':pending', 'Governance/DecisionCardsTest', 'test_pending_party_and_evaluation_after_a_finished_project'],
        70 => [self::CONTRACTOR, ':eval:', 'Governance/DecisionCardsTest', 'test_pending_party_and_evaluation_after_a_finished_project'],
        73 => [self::ACCOUNT, ':returned', 'Governance/DecisionCardsTest', 'test_returned_account_asks_whoever_registered_it_and_correcting_it_resubmits'],
        74 => [self::READINESS, 'coordgap:', 'Governance/ReadinessCardsTest', 'test_department_without_a_coordinator_asks_its_manager'],
        // ٢٧-ج
        34 => [self::DEADLINE, 'drill:', 'Governance/DeadlineCardsTest', 'test_scheduled_drill_asks_its_owners_seven_days_ahead_and_turns_red_after'],
        35 => [self::DEADLINE, 'eqinspect:', 'Governance/DeadlineCardsTest', 'test_emergency_equipment_due_for_inspection_asks_its_owners_until_inspected'],
        39 => [self::DEADLINE, 'visitors:', 'Governance/DeadlineCardsTest', 'test_visitors_past_their_checkout_ask_security_and_the_center_in_one_card'],
        40 => [self::DEADLINE, "'medreview'", 'Governance/DeadlineCardsTest', 'test_medical_profiles_needing_review_ask_the_clinic_doctor_with_the_dashboard_count'],
        55 => [self::DEADLINE, ':expiring', 'Governance/DeadlineCardsTest', 'test_active_permit_near_its_end_asks_those_who_close_it'],
        59 => [self::DEADLINE, 'epceq:', 'Governance/DeadlineCardsTest', 'test_permit_equipment_due_for_inspection_asks_its_managers_until_inspected'],
        65 => [self::DEADLINE, 'epdoc:', 'Governance/DeadlineCardsTest', 'test_party_document_near_expiry_asks_the_center_and_the_party_until_replaced'],
        66 => [self::DEADLINE, 'wdoc:', 'Governance/DeadlineCardsTest', 'test_worker_document_near_expiry_asks_those_who_edit_workers_within_their_party'],
        67 => [self::DEADLINE, 'wtrain:', 'Governance/DeadlineCardsTest', 'test_worker_training_near_expiry_asks_those_who_record_training'],
        68 => [self::DEADLINE, 'cverify:', 'Governance/DeadlineCardsTest', 'test_expired_contractor_verification_asks_the_center_to_reverify_in_one_press'],
    ];

    /** ليست حالة انتظار — القراءة خالفت الجرد */
    private const NOT_WAITING = [
        3 => 'المنسق «يستلم البلاغ»: النظام يؤدي الخطوة وحده ويُشعر المنسق (IncidentService::stepsToHandler) — لا بلاغ يقف عنده',
        4 => 'المنسق «يحوّله للفني»: كذلك — النظام يحوّله',
        57 => 'التصريح المرفوض: الرفض نهاية في آلة الحالة ولا مسار لإعادة التقديم',
    ];

    /** موعد بلا شاشة تسجّله — مؤجلة بانتظار قرار المستخدم (BACKEND.md المؤجلات، ٢٠٢٦-٠٩-٣٠) */
    private const DEFERRED = [
        36 => 'انتهاء صلاحية معدة الطوارئ: التاريخ يُكتب عند الإضافة وحدها، لا «جُدّدت» ولا تعديل',
        37 => 'تدقيق المبنى الدوري: next_audit_date حقل لا تكتبه ولا تقرؤه أي شاشة',
        63 => 'انتهاء تأهيل المقاول: expires_at لا تضبطه أي شاشة، و«منتهٍ» نهاية',
    ];

    private function file(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    public function test_each_of_the_37_gaps_has_one_written_fate(): void
    {
        $fates = array_merge(array_keys(self::CARDS), array_keys(self::NOT_WAITING), array_keys(self::DEFERRED));
        sort($fates);
        $this->assertSame(self::GAPS, $fates, 'حالة من الـ٣٧ بلا مصير، أو لها مصيران');
        $this->assertCount(37, self::GAPS);
        $this->assertCount(31, self::CARDS);
        $this->assertCount(3, self::NOT_WAITING);
        $this->assertCount(3, self::DEFERRED);
    }

    public function test_every_card_has_a_registered_source_its_key_and_a_guarding_test(): void
    {
        foreach (self::CARDS as $no => [$source, $key, $testFile, $test]) {
            $this->assertContains($source, InboxService::SOURCES, "الحالة {$no}: مصدرها غير مسجَّل في «ما ينتظرك»");
            $this->assertStringContainsString($key, $this->file('app/'.str_replace(['App\\', '\\'], ['', '/'], $source).'.php'), "الحالة {$no}: المفتاح «{$key}» ليس في مصدره");
            $this->assertStringContainsString('function '.$test.'(', $this->file("tests/Feature/{$testFile}.php"), "الحالة {$no}: اختبارها «{$test}» غير موجود");
        }
    }

    public function test_states_that_are_not_waiting_stay_without_a_promise(): void
    {
        $this->assertStringContainsString('function stepsToHandler', $this->file('app/Modules/Incident/Services/IncidentService.php'), 'الحالتان ٣ و٤: النظام لم يعد يؤدي خطوتي المنسق');
        $this->assertStringContainsString('function test_rejected_permit_is_not_a_waiting_state(', $this->file('tests/Feature/Permit/PermitDecisionCardsTest.php'), 'الحالة ٥٧ بلا حارس');
    }

    /** يوم تُبنى شاشة لأحد المواعيد الثلاثة يسقط هذا الاختبار: أضف بطاقته في DeadlineTasks وانقله من DEFERRED إلى CARDS */
    public function test_deferred_deadlines_still_have_no_screen_that_sets_them(): void
    {
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();
        // ٣٦: لا مسار يعدّل معدة الطوارئ ولا يجدّدها
        $this->assertSame([], $names->filter(fn ($n) => str_starts_with($n, 'emergency.equipment.') && !in_array($n, ['emergency.equipment.index', 'emergency.equipment.create', 'emergency.equipment.store', 'emergency.equipment.inspect'], true))->values()->all(),
            'صار لمعدة الطوارئ مسار جديد — إن كان يجدّد الصلاحية فأضف بطاقة «انتهت صلاحيتها» (٣٦)');
        // ٣٧: لا شاشة ولا متحكم يذكر موعد التدقيق
        $this->assertStringNotContainsString('next_audit_date', $this->file('app/Modules/Emergency/Controllers/EmergencyController.php'), 'صار لتدقيق المبنى مسار — أضف بطاقته (٣٧)');
        foreach (glob(base_path('resources/views/modules/emergency/buildings/*.blade.php')) as $view) {
            $this->assertStringNotContainsString('next_audit_date', (string) file_get_contents($view), 'صار لتدقيق المبنى شاشة — أضف بطاقته (٣٧)');
        }
        // ٦٣: لا متحكم يضبط انتهاء التأهيل
        $this->assertStringNotContainsString('expires_at', $this->file('app/Modules/Project/Controllers/ProjectController.php'), 'صار لانتهاء تأهيل المقاول مسار — أضف بطاقته (٦٣)');
    }
}
