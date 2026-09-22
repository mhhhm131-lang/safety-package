<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * حصر الأشخاص عند نقاط التجمع (من OHSMS بلا tenant).
 * المعهد: عند التفعيل يُنشأ سجل لكل حساب مفعّل (كما OHSMS) ولكل عضو في الفريق الأولي للمكان بلا حساب (person_type=team)
 * حتى يظهر في شاشة التتبع ويُعلَّم وصوله. الشاغلون بلا حساب يُحصرون يدوياً أو كزوار.
 */
class QrMusteringService
{
    public function generateQrCodesForIncident(EmergencyIncident $incident): void
    {
        $userIds = UserProfile::where('is_active', true)->pluck('user_id')->unique();
        foreach ($userIds as $userId) {
            EvacuationCheckIn::create([
                'incident_id' => $incident->id, 'user_id' => $userId, 'person_type' => 'employee', 'status' => 'evacuating',
            ]);
        }

        if ($incident->place_id) {
            $teams = EmergencyTeam::active()->where('place_id', $incident->place_id)->with('members')->get();
            foreach ($teams as $team) {
                foreach ($team->members as $member) {
                    if ($member->user_id) continue; // له حساب: سُجّل أعلاه
                    if (!$member->name) continue;
                    EvacuationCheckIn::create([
                        'incident_id' => $incident->id, 'team_member_id' => $member->id, 'visitor_name' => $member->name,
                        'visitor_phone' => $member->phone, 'person_type' => 'team', 'status' => 'evacuating',
                    ]);
                }
            }
        }
    }

    public function checkInByQr(string $qrToken, AssemblyPoint $point, User $checkedBy): EvacuationCheckIn
    {
        $checkIn = EvacuationCheckIn::where('qr_token', $qrToken)->firstOrFail();
        return $this->performCheckIn($checkIn, $point, $checkedBy, 'qr_scan');
    }

    public function selfCheckIn(EvacuationCheckIn $checkIn, AssemblyPoint $point, ?float $lat = null, ?float $lng = null): EvacuationCheckIn
    {
        return $this->performCheckIn($checkIn, $point, null, 'self', $lat, $lng);
    }

    /** ٢٢-١٤: «أنا بخير» من رسالة المركز — بأمان بلا نقطة تجمع (لا نفترض أيّ نقطة وقف فيها). */
    public function markSafeByMessage(EvacuationCheckIn $checkIn): EvacuationCheckIn
    {
        return $this->performCheckIn($checkIn, null, null, EvacuationCheckIn::METHOD_MESSAGE);
    }

    public function manualCheckIn(EvacuationCheckIn $checkIn, ?AssemblyPoint $point, User $checkedBy): EvacuationCheckIn
    {
        return $this->performCheckIn($checkIn, $point, $checkedBy, 'manual');
    }

    protected function performCheckIn(EvacuationCheckIn $checkIn, ?AssemblyPoint $point, ?User $checkedBy, string $method, ?float $lat = null, ?float $lng = null): EvacuationCheckIn
    {
        $checkIn->update([
            'status' => EvacuationCheckIn::STATUS_SAFE,
            'checked_in_at' => now(),
            'assembly_point_id' => $point?->id,
            'checked_by_id' => $checkedBy?->id,
            'check_in_method' => $method,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
        ]);

        EmergencyEventLog::log(
            $checkIn->incident,
            EmergencyEventLog::TYPE_PERSON_SAFE,
            'وصل بأمان: '.$checkIn->getPersonName().($point ? ' — '.$point->name : ''),
            ['person_type' => $checkIn->person_type, 'assembly_point' => $point?->name, 'method' => $method],
            'info',
            $checkedBy?->id ?? $checkIn->user_id
        );

        return $checkIn;
    }

    public function markAsMissing(EvacuationCheckIn $checkIn, ?User $markedBy = null): void
    {
        $checkIn->update(['status' => EvacuationCheckIn::STATUS_MISSING]);
        EmergencyEventLog::log($checkIn->incident, EmergencyEventLog::TYPE_PERSON_MISSING, 'مفقود: '.$checkIn->getPersonName(),
            ['last_known_location' => $checkIn->last_known_location, 'floor' => $checkIn->floor?->getDisplayName()], 'warning', $markedBy?->id);
    }

    public function markAsFound(EvacuationCheckIn $checkIn, ?User $foundBy = null, ?string $notes = null): void
    {
        $checkIn->update(['status' => EvacuationCheckIn::STATUS_SAFE, 'checked_in_at' => $checkIn->checked_in_at ?? now(), 'notes' => $notes]);
        EmergencyEventLog::log($checkIn->incident, EmergencyEventLog::TYPE_PERSON_FOUND, 'تم العثور على: '.$checkIn->getPersonName(), [], 'info', $foundBy?->id);
    }

    /**
     * طلب المساعدة: علامة على السجل، **لا إخراج من الحصر**.
     * (عيب كُشف ٢٠٢٦-٠٩-٢٢: من سجّل وصوله «بأمان» ثم طلب مساعدة كان يخرج من عدّاد الآمنين،
     * فيقول العدّاد صفراً ورجلٌ واقف في نقطة التجمع. الإصابة وحدها تغيّر حالة الآمن.)
     */
    public function requestHelp(EvacuationCheckIn $checkIn, string $helpType, ?string $location = null, ?string $notes = null): void
    {
        $status = $helpType === 'injured'
            ? EvacuationCheckIn::STATUS_INJURED
            : ($checkIn->isSafe() ? EvacuationCheckIn::STATUS_SAFE : EvacuationCheckIn::STATUS_ASSISTED);
        $checkIn->update([
            'status' => $status,
            'needs_assistance' => true,
            'assistance_type' => $helpType,
            'last_known_location' => $location ?? $checkIn->last_known_location,
            'notes' => $notes,
        ]);
        $this->notifyHelpRequest($checkIn, $location);
        EmergencyEventLog::log($checkIn->incident, EmergencyEventLog::TYPE_HELP_REQUESTED,
            'طلب مساعدة: '.$checkIn->getPersonName().' — '.$checkIn->getAssistanceTypeLabel(),
            ['help_type' => $helpType, 'location' => $location], 'critical', $checkIn->user_id);
    }

    /**
     * الطلب يصل فعلاً: المركز، وأعضاء الفريق الأولي لمكان الحالة ممن لهم حسابات.
     * (كان لا يصل أحداً ويظهر فقط لمن شاشة الحالة مفتوحة أمامه، بينما شاشة الموظف تقول
     * «فريق الاستجابة والمركز يريانه الآن» — عيب كُشف ٢٠٢٦-٠٩-٢٢.)
     */
    protected function notifyHelpRequest(EvacuationCheckIn $checkIn, ?string $location): void
    {
        $incident = $checkIn->incident;
        if (!$incident) return;

        $title = 'طلب مساعدة: '.$checkIn->getPersonName().($location ? ' — '.$location : '');
        $body = $checkIn->getAssistanceTypeLabel().($checkIn->notes ? ' — '.$checkIn->notes : '');
        $url = route('emergency.incidents.live', $incident);

        $ids = UserProfile::where('is_active', true)
            ->whereIn('role', ['system_admin', 'system_staff'])->pluck('user_id');
        $team = EmergencyTeam::active()
            ->where(fn ($w) => $w->where('place_id', $incident->place_id)->orWhereNull('place_id'))
            ->with('members')->get()->flatMap->members->pluck('user_id')->filter();

        $inbox = app(\App\Core\Services\NotificationService::class);
        foreach ($ids->merge($team)->unique() as $userId) {
            if ((int) $userId === (int) $checkIn->user_id) continue;   // صاحب الطلب لا يُنبَّه بطلبه
            $inbox->create((int) $userId, 'emergency.help_requested', $title, $body, $url);
        }
    }

    public function getLiveStats(EmergencyIncident $incident): array
    {
        $q = EvacuationCheckIn::where('incident_id', $incident->id);
        return [
            'total' => (clone $q)->count(),
            'safe' => (clone $q)->where('status', 'safe')->count(),
            'evacuating' => (clone $q)->where('status', 'evacuating')->count(),
            'missing' => (clone $q)->where('status', 'missing')->count(),
            'injured' => (clone $q)->where('status', 'injured')->count(),
            'assisted' => (clone $q)->where('status', 'assisted')->count(),
            'needs_help' => (clone $q)->where('needs_assistance', true)->count(),
            'team_total' => (clone $q)->where('person_type', 'team')->count(),
            'team_arrived' => (clone $q)->where('person_type', 'team')->where('status', 'safe')->count(),
        ];
    }

    public function getStatsByAssemblyPoint(EmergencyIncident $incident): Collection
    {
        return EvacuationCheckIn::where('incident_id', $incident->id)->where('status', 'safe')->whereNotNull('assembly_point_id')
            ->select('assembly_point_id', DB::raw('count(*) as count'))->groupBy('assembly_point_id')
            ->with('assemblyPoint:id,name,code')->get();
    }

    public function getStatsByFloor(EmergencyIncident $incident): Collection
    {
        return EvacuationCheckIn::where('incident_id', $incident->id)->whereNotNull('floor_id')
            ->select('floor_id', 'status', DB::raw('count(*) as count'))->groupBy('floor_id', 'status')
            ->with('floor:id,floor_number,name')->get();
    }

    public function getMissingPeople(EmergencyIncident $incident): Collection
    {
        return EvacuationCheckIn::where('incident_id', $incident->id)->whereIn('status', ['evacuating', 'missing'])
            ->with(['user:id,name', 'teamMember', 'floor:id,floor_number,name'])->orderByRaw("case when status='missing' then 0 else 1 end")->get();
    }

    public function getPeopleNeedingHelp(EmergencyIncident $incident): Collection
    {
        return EvacuationCheckIn::where('incident_id', $incident->id)->where('needs_assistance', true)
            ->with(['user:id,name', 'teamMember', 'floor:id,floor_number,name'])->get();
    }

    public function getUserCheckIn(User $user): ?EvacuationCheckIn
    {
        return EvacuationCheckIn::where('user_id', $user->id)
            ->whereHas('incident', fn ($q) => $q->whereIn('status', EmergencyIncident::OPEN_STATUSES))
            ->latest()->first();
    }
}
