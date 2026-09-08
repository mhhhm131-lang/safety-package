<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyVisitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitorService
{
    /**
     * Check in a visitor
     */
    public function checkIn(array $data): EmergencyVisitor
    {
        return DB::transaction(function () use ($data) {
            $visitor = EmergencyVisitor::create([
                'building_id' => $data['building_id'],
                'place_id' => $data['place_id'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'company' => $data['company'] ?? null,
                'id_number' => $data['id_number'] ?? null,
                'id_type' => $data['id_type'] ?? null,
                'photo_mime' => $data['photo_mime'] ?? null,
                'photo_data' => $data['photo_data'] ?? null,
                'host_user_id' => $data['host_user_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'badge_number' => $data['badge_number'] ?? $this->generateBadgeNumber(),
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'expected_checkout_at' => $data['expected_checkout_at'] ?? null,
                'needs_assistance' => $data['needs_assistance'] ?? false,
                'assistance_type' => $data['assistance_type'] ?? null,
                'special_notes' => $data['special_notes'] ?? null,
                'qr_token' => Str::random(64),
            ]);

            $this->logEvent($visitor, 'visitor_checked_in', [
                'building' => $visitor->building->name,
                'host' => $visitor->host?->name,
            ]);

            Log::info("Visitor checked in", [
                'visitor_id' => $visitor->id,
                'building_id' => $visitor->building_id,
            ]);

            return $visitor;
        });
    }

    /**
     * Check out a visitor
     */
    public function checkOut(EmergencyVisitor $visitor): void
    {
        $visitor->checkOut();

        $this->logEvent($visitor, 'visitor_checked_out', [
            'duration_minutes' => $visitor->getDurationMinutes(),
        ]);

        Log::info("Visitor checked out", [
            'visitor_id' => $visitor->id,
            'duration' => $visitor->getDurationMinutes(),
        ]);
    }

    /**
     * Get visitors currently in a building
     */
    public function getVisitorsInBuilding(int $buildingId): Collection
    {
        return EmergencyVisitor::inBuilding($buildingId)
            ->with(['host', 'assemblyPoint'])
            ->orderByDesc('checked_in_at')
            ->get();
    }

    /**
     * Get today's visitors for a building
     */
    public function getTodayVisitors(int $buildingId): Collection
    {
        return EmergencyVisitor::where('building_id', $buildingId)
            ->today()
            ->with(['host'])
            ->orderByDesc('checked_in_at')
            ->get();
    }

    /**
     * Get visitor by QR token
     */
    public function getByQrToken(string $token): ?EmergencyVisitor
    {
        return EmergencyVisitor::where('qr_token', $token)->first();
    }

    /**
     * Mark visitor safe during evacuation
     */
    public function markVisitorSafe(EmergencyVisitor $visitor, ?AssemblyPoint $assemblyPoint = null): void
    {
        $visitor->markSafe($assemblyPoint);

        $this->logEvent($visitor, 'visitor_marked_safe', [
            'assembly_point' => $assemblyPoint?->name,
        ]);
    }

    /**
     * Mark visitor as needing help
     */
    public function markVisitorNeedHelp(EmergencyVisitor $visitor, ?string $location = null): void
    {
        $visitor->markNeedHelp($location);

        $this->logEvent($visitor, 'visitor_needs_help', [
            'location' => $location,
        ]);
    }

    /**
     * Get evacuation stats for visitors in a building
     */
    public function getEvacuationStats(int $buildingId): array
    {
        $visitors = EmergencyVisitor::inBuilding($buildingId)->get();

        return [
            'total' => $visitors->count(),
            'safe' => $visitors->where('evacuation_status', EmergencyVisitor::EVAC_SAFE)->count(),
            'evacuating' => $visitors->where('evacuation_status', EmergencyVisitor::EVAC_EVACUATING)->count(),
            'need_help' => $visitors->where('evacuation_status', EmergencyVisitor::EVAC_NEED_HELP)->count(),
            'missing' => $visitors->whereIn('evacuation_status', [EmergencyVisitor::EVAC_UNKNOWN, EmergencyVisitor::EVAC_MISSING])->count(),
            'needs_assistance' => $visitors->where('needs_assistance', true)->count(),
        ];
    }

    /**
     * Get visitors needing evacuation help
     */
    public function getVisitorsNeedingHelp(int $buildingId): Collection
    {
        return EmergencyVisitor::inBuilding($buildingId)
            ->needsEvacuationHelp()
            ->with(['assemblyPoint'])
            ->get();
    }

    /**
     * Get missing visitors
     */
    public function getMissingVisitors(int $buildingId): Collection
    {
        return EmergencyVisitor::inBuilding($buildingId)
            ->missing()
            ->get();
    }

    /**
     * Reset all visitor evacuation statuses after incident ends
     */
    public function resetEvacuationStatuses(int $buildingId): void
    {
        EmergencyVisitor::where('building_id', $buildingId)
            ->currentlyIn()
            ->update([
                'evacuation_status' => EmergencyVisitor::EVAC_UNKNOWN,
                'evacuation_checked_at' => null,
                'evacuation_location' => null,
                'evacuation_assembly_point_id' => null,
            ]);
    }

    /**
     * Search visitors
     */
    public function search(string $query, ?int $buildingId = null): Collection
    {
        return EmergencyVisitor::query()
            ->when($buildingId, fn($q) => $q->where('building_id', $buildingId))
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%")
                  ->orWhere('company', 'like', "%{$query}%")
                  ->orWhere('badge_number', 'like', "%{$query}%");
            })
            ->with(['building', 'host'])
            ->orderByDesc('checked_in_at')
            ->limit(50)
            ->get();
    }

    /**
     * Get visitor statistics for a tenant
     */
    public function getStats(string $period = 'today'): array
    {
        $query = EmergencyVisitor::query();

        $query = match($period) {
            'today' => $query->whereDate('checked_in_at', today()),
            'week' => $query->where('checked_in_at', '>=', now()->startOfWeek()),
            'month' => $query->where('checked_in_at', '>=', now()->startOfMonth()),
            default => $query->whereDate('checked_in_at', today()),
        };

        $visitors = $query->get();

        return [
            'total' => $visitors->count(),
            'currently_in' => $visitors->where('status', EmergencyVisitor::STATUS_CHECKED_IN)
                ->whereNull('checked_out_at')->count(),
            'checked_out' => $visitors->where('status', EmergencyVisitor::STATUS_CHECKED_OUT)->count(),
            'needs_assistance' => $visitors->where('needs_assistance', true)->count(),
            'avg_duration_minutes' => (int) $visitors->where('checked_out_at', '!=', null)
                ->avg(fn($v) => $v->getDurationMinutes()),
            'by_building' => $visitors->groupBy('building_id')
                ->map(fn($group) => $group->count()),
        ];
    }

    /**
     * Generate badge number
     */
    protected function generateBadgeNumber(): string
    {
        $date = now()->format('ymd');
        $random = strtoupper(Str::random(4));
        return "V{$date}-{$random}";
    }

    /**
     * Log visitor event
     */
    protected function logEvent(EmergencyVisitor $visitor, string $action, array $details = []): void
    {
        // السجل الزمني يخص حالة طارئة مفتوحة في المبنى؛ بلا حالة يكفي سجل التدقيق (HasAuditLog على EmergencyVisitor)
        $incident = EmergencyIncident::open()->where('building_id', $visitor->building_id)->latest('triggered_at')->first();
        if (!$incident || !in_array($action, ['visitor_marked_safe', 'visitor_needs_help'], true)) return;
        EmergencyEventLog::create([
            'incident_id' => $incident->id,
            'event_type' => EmergencyEventLog::TYPE_VISITOR,
            'severity' => $action === 'visitor_needs_help' ? 'critical' : 'info',
            'message' => $this->getActionDescription($action).': '.$visitor->name,
            'user_id' => auth()->id(),
            'logged_at' => now(),
            'data' => array_merge($details, ['visitor_id' => $visitor->id, 'action' => $action]),
        ]);
    }

    protected function getActionDescription(string $action): string
    {
        return match($action) {
            'visitor_checked_in' => 'تسجيل دخول زائر',
            'visitor_checked_out' => 'تسجيل خروج زائر',
            'visitor_marked_safe' => 'تم تأكيد سلامة الزائر',
            'visitor_needs_help' => 'الزائر يحتاج مساعدة',
            default => $action,
        };
    }
}
