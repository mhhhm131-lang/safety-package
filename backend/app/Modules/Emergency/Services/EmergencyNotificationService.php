<?php

namespace App\Modules\Emergency\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Core\Services\NotificationService;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyContact;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * تنبيهات الحالة الطارئة (من OHSMS) بقناتي المعهد المقررتين (BACKEND.md §٦): داخل النظام + بريد.
 * لا SMS ولا واتساب ولا Slack/Teams (وحدة Integration ليست في النطاق، والواتساب مرفوض بقرار §٦).
 *
 * من يُنبَّه عند التفعيل — من خطة الاستجابة («المركز يُنادي فوراً: فريق الطابق + الإسناد + القيادة»):
 *  ١. الفريق الأولي للمكان (مشتق من ملف المكان): من له حساب يصله إشعار وبريد؛ من بلا حساب يُسجَّل نداءً هاتفياً يدوياً باسمه ورقمه.
 *  ٢. الإسناد والقيادة: مسؤول السلامة والمناوب والمنسق ومدير الشؤون الإدارية والهندسية ومدير المرافق ورئيس الأمن والسلامة وفريق الإسناد.
 *  ٣. الإدارة العليا ولجنة السلامة (تقرير).
 *  ٤. جهات الاتصال ذات التنبيه الآلي (الدفاع المدني…): بريد إن وُجد، وإلا نداء هاتفي يدوي.
 *  ٥. كل الحسابات المفعّلة (الشاغلون بحساب) بتعليمات الإخلاء.
 */
class EmergencyNotificationService
{
    /** أدوار الإسناد والقيادة التي تُنادى فور التفعيل (خطة الاستجابة، والقرار ٢٠٢٦-٠٩-٠٨ عن أدوار المعهد في الطوارئ). */
    public const COMMAND_ROLES = [
        'system_admin', 'system_staff', 'safety_coordinator',
        'admin_eng_manager', 'facilities_manager', 'security_safety_head', 'support_team',
    ];

    public const MANAGEMENT_ROLES = ['top_management', 'safety_committee'];

    public function __construct(protected NotificationService $inbox) {}

    public function notifyAll(EmergencyIncident $incident): void
    {
        $this->notifyTeams($incident);
        $this->notifyCommand($incident);
        $this->notifyManagement($incident);
        $this->notifyContacts($incident);
        $this->notifyOccupants($incident);
    }

    /** ١. الفريق الأولي للمكان + الفرق اليدوية النشطة في المبنى. */
    public function notifyTeams(EmergencyIncident $incident): void
    {
        $teams = EmergencyTeam::active()->with('members.user')
            ->where(function ($q) use ($incident) {
                $q->where('building_id', $incident->building_id)->orWhereNull('building_id');
            })
            ->when($incident->place_id, fn ($q) => $q->where(fn ($w) => $w->where('place_id', $incident->place_id)->orWhereNull('place_id')))
            ->get();

        $notified = 0;
        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $subject = '['.$team->getTypeLabel().'] '.$incident->getAlertMessage();
                $body = 'توجّه فوراً إلى الموقع. النوع: '.$incident->getTypeLabel().' — الخطورة: '.$incident->getSeverityLabel();
                if ($member->user) {
                    $this->sendToUser($incident, $member->user, $subject, $body, 'emergency.triggered');
                } else {
                    $this->recordPhoneCall($incident, 'team_member', $member->id, $member->displayName(), $member->getDisplayPhone(), $subject, $body);
                }
                $notified++;
            }
        }
        EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_TEAM_NOTIFIED,
            'نُبّه الفريق: '.$notified.' عضواً في '.$teams->count().' فريق'.($incident->place ? ' — '.$incident->place->name : ''),
            ['teams' => $teams->pluck('name')->all(), 'members' => $notified], 'info', $incident->triggered_by_id);
    }

    /** ٢. الإسناد والقيادة. */
    public function notifyCommand(EmergencyIncident $incident): void
    {
        foreach ($this->usersWithRoles(self::COMMAND_ROLES) as $user) {
            $this->sendToUser($incident, $user, $incident->getAlertMessage(), $this->getManagementReport($incident), 'emergency.triggered');
        }
    }

    /** ٣. الإدارة العليا ولجنة السلامة. */
    public function notifyManagement(EmergencyIncident $incident): void
    {
        foreach ($this->usersWithRoles(self::MANAGEMENT_ROLES) as $user) {
            $this->sendToUser($incident, $user, '[للإدارة] '.$incident->getAlertMessage(), $this->getManagementReport($incident), 'emergency.triggered');
        }
    }

    /** ٤. جهات الاتصال ذات التنبيه الآلي. */
    public function notifyContacts(EmergencyIncident $incident): void
    {
        if ($incident->is_drill) return; // التمرين لا يُبلَّغ للجهات الخارجية
        $contacts = EmergencyContact::active()->autoNotify()->byPriority()
            ->where(fn ($q) => $q->where('building_id', $incident->building_id)->orWhereNull('building_id'))->get();
        foreach ($contacts as $contact) {
            $subject = $incident->getAlertMessage();
            if ($contact->email) {
                $this->sendEmail($incident, 'contact', $contact->id, $contact->name, $contact->email, $subject, $this->getDetailedMessage($incident));
            }
            $this->recordPhoneCall($incident, 'contact', $contact->id, $contact->name, $contact->phone, $subject, 'نداء هاتفي من المركز');
        }
        if ($contacts->isNotEmpty()) {
            EmergencyEventLog::log($incident, EmergencyEventLog::TYPE_EXTERNAL_NOTIFIED,
                'جهات الاتصال المطلوب نداؤها: '.$contacts->pluck('name')->join('، '), ['contacts' => $contacts->pluck('name')->all()], 'warning', $incident->triggered_by_id);
        }
    }

    /** ٥. كل الحسابات المفعّلة بتعليمات الإخلاء (عدا من نُبّه أعلاه). */
    public function notifyOccupants(EmergencyIncident $incident): void
    {
        $already = EmergencyNotification::where('incident_id', $incident->id)->where('recipient_type', 'user')->pluck('recipient_id')->all();
        $users = User::whereHas('profile', fn ($q) => $q->where('is_active', true))->whereNotIn('id', $already)->get();
        foreach ($users as $user) {
            $this->sendToUser($incident, $user, $incident->getAlertMessage(), $this->getEvacuationInstructions($incident), 'emergency.triggered');
        }
    }

    public function notifyAllClear(EmergencyIncident $incident): void
    {
        $message = 'انتهى الخطر — '.($incident->place?->name ?? $incident->building->name).'. يمكنكم العودة بأمان.';
        foreach (User::whereHas('profile', fn ($q) => $q->where('is_active', true))->get() as $user) {
            $this->sendToUser($incident, $user, 'انتهى الخطر', $message, 'emergency.ended');
        }
    }

    public function notifyCancelled(EmergencyIncident $incident, string $reason): void
    {
        $message = 'أُلغيت الحالة الطارئة '.$incident->incident_code.' — '.$reason;
        foreach ($this->usersWithRoles(array_merge(self::COMMAND_ROLES, self::MANAGEMENT_ROLES)) as $user) {
            $this->sendToUser($incident, $user, 'إلغاء حالة طارئة', $message, 'emergency.cancelled');
        }
    }

    /** تصعيد آلي (AutoEscalationService): أدوار المستوى + نداء الجهات الخارجية عند المستوى الأخير. */
    public function notifyEscalation(EmergencyIncident $incident, array $roles, string $subject, string $body): void
    {
        foreach ($this->usersWithRoles($roles) as $user) {
            $this->sendToUser($incident, $user, $subject, $body, 'emergency.escalated');
        }
    }

    /** إشعار نصي عام مرتبط بحالة (يستعمله الإغلاق الأمني والرسائل الجماعية). */
    public function notifyUsers(?EmergencyIncident $incident, iterable $users, string $subject, string $body, string $type = 'emergency.message'): int
    {
        $n = 0;
        foreach ($users as $user) {
            $this->sendToUser($incident, $user, $subject, $body, $type);
            $n++;
        }
        return $n;
    }

    // ── القنوات ──

    protected function sendToUser(?EmergencyIncident $incident, User $user, string $subject, string $body, string $type): void
    {
        $row = EmergencyNotification::create([
            'incident_id' => $incident?->id, 'channel' => EmergencyNotification::CHANNEL_IN_APP, 'recipient_type' => 'user',
            'recipient_id' => $user->id, 'recipient_name' => $user->name, 'recipient_contact' => $user->email,
            'subject' => $subject, 'body' => $body, 'status' => 'pending',
        ]);
        try {
            $url = $incident ? '/app/emergency/incidents/'.$incident->id.'/live' : '/app/emergency';
            // NotificationService يرسل البريد أيضاً إن كان للمستخدم بريد (§٦)
            $this->inbox->create($user->id, $type, $subject, $body, $url);
            $row->markAsSent();
        } catch (\Throwable $e) {
            Log::error('Emergency notification failed: '.$e->getMessage(), ['user' => $user->id]);
            $row->markAsFailed($e->getMessage());
        }
    }

    protected function sendEmail(?EmergencyIncident $incident, string $recipientType, ?int $recipientId, string $name, string $email, string $subject, string $body): void
    {
        $row = EmergencyNotification::create([
            'incident_id' => $incident?->id, 'channel' => EmergencyNotification::CHANNEL_EMAIL, 'recipient_type' => $recipientType,
            'recipient_id' => $recipientId, 'recipient_name' => $name, 'recipient_contact' => $email,
            'subject' => $subject, 'body' => $body, 'status' => 'pending',
        ]);
        try {
            Mail::raw($body, function ($m) use ($email, $name, $subject) {
                $m->to($email, $name)->subject('[طوارئ] '.$subject);
            });
            $row->markAsSent();
        } catch (\Throwable $e) {
            report($e);
            $row->markAsFailed($e->getMessage());
        }
    }

    /** بلا حساب وبلا بريد: يُسجَّل للمركز ليُنادى هاتفياً (الفريق الأولي ميداني بلا عمل رقمي). */
    protected function recordPhoneCall(?EmergencyIncident $incident, string $recipientType, ?int $recipientId, string $name, ?string $phone, string $subject, string $body): void
    {
        EmergencyNotification::create([
            'incident_id' => $incident?->id, 'channel' => EmergencyNotification::CHANNEL_PHONE, 'recipient_type' => $recipientType,
            'recipient_id' => $recipientId, 'recipient_name' => $name, 'recipient_contact' => $phone,
            'subject' => $subject, 'body' => $body, 'status' => $phone ? EmergencyNotification::STATUS_MANUAL : EmergencyNotification::STATUS_FAILED,
            'error_message' => $phone ? null : 'لا رقم هاتف مسجّل',
        ]);
    }

    protected function usersWithRoles(array $roles)
    {
        $roles = array_values(array_intersect($roles, array_keys(PermissionRegistry::ROLES)));
        $ids = UserProfile::whereIn('role', $roles)->where('is_active', true)->pluck('user_id')->unique();
        return User::whereIn('id', $ids)->get();
    }

    // ── النصوص ──

    public function getEvacuationInstructions(EmergencyIncident $incident): string
    {
        $primaryPoint = $incident->building->getPrimaryAssemblyPoint();
        $pointName = $primaryPoint ? $primaryPoint->name : 'نقطة التجمع المحددة';
        $base = "الرجاء إخلاء المبنى فوراً والتوجه إلى {$pointName}.";
        $typeInstructions = match ($incident->incident_type) {
            'fire' => 'لا تستخدم المصاعد. استخدم الدرج فقط. غطِّ أنفك بقطعة قماش مبللة إن وُجد دخان.',
            'earthquake' => 'ابتعد عن النوافذ والأثاث الثقيل. احمِ رأسك.',
            'chemical_spill' => 'غطِّ أنفك وفمك. ابتعد عن منطقة التسرب.',
            'gas_leak' => 'لا تستخدم أي مصادر إشعال. افتح النوافذ إن أمكن.',
            'bomb_threat' => 'لا تلمس أي أغراض مشبوهة. ابتعد فوراً.',
            'lockdown' => 'ابقَ في مكانك وأقفل الأبواب وابتعد عن النوافذ حتى إشعار آخر.',
            default => '',
        };
        return trim("{$base} {$typeInstructions}");
    }

    protected function getDetailedMessage(EmergencyIncident $incident): string
    {
        $where = $incident->place?->name ?? $incident->building->name;
        return "حالة طوارئ — {$where}\n\nالنوع: {$incident->getTypeLabel()}\nالخطورة: {$incident->getSeverityLabel()}\nالوقت: {$incident->triggered_at->format('Y-m-d H:i:s')}\n\n{$this->getEvacuationInstructions($incident)}\n\n— منظومة السلامة والصحة المهنية، معهد الإدارة العامة";
    }

    protected function getManagementReport(EmergencyIncident $incident): string
    {
        $where = $incident->place?->name ?? $incident->building->name;
        return "المكان: {$where}\nالنوع: {$incident->getTypeLabel()}\nالخطورة: {$incident->getSeverityLabel()}\nتمرين: ".($incident->is_drill ? 'نعم' : 'لا')."\nوقت البدء: {$incident->triggered_at->format('Y-m-d H:i:s')}\nبواسطة: ".($incident->triggeredBy?->name ?? 'غير محدد')."\n\nالوصف: {$incident->description}\n\nالمتابعة المباشرة: ".url('/app/emergency/incidents/'.$incident->id.'/live');
    }
}
