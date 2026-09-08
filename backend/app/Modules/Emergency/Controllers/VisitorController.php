<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\AssemblyPoint;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyVisitor;
use App\Modules\Emergency\Services\VisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function __construct(
        protected VisitorService $visitorService
    ) {}

    /**
     * Check in a new visitor
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'building_id' => 'nullable|exists:emergency_buildings,id',
            'place_id' => 'nullable|exists:places,id',
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'company' => 'nullable|string|max:100',
            'id_number' => 'nullable|string|max:50',
            'id_type' => 'nullable|in:national_id,passport,employee_id,driver_license',
            'host_user_id' => 'nullable|exists:users,id',
            'purpose' => 'nullable|string|max:200',
            'vehicle_plate' => 'nullable|string|max:20',
            'expected_checkout_at' => 'nullable|date|after:now',
            'needs_assistance' => 'nullable|boolean',
            'assistance_type' => 'nullable|in:wheelchair,visual,hearing,mobility,medical,other',
            'special_notes' => 'nullable|string|max:500',
            'photo' => 'nullable|image|max:5120',
        ]);

        $validated['building_id'] = $validated['building_id'] ?? EmergencyBuilding::main()?->id;
        if (!$validated['building_id']) {
            return response()->json(['success' => false, 'message' => 'لا مبنى مسجّل'], 422);
        }
        // الصورة في القاعدة base64 (Render بلا قرص دائم)
        if ($request->hasFile('photo')) {
            $f = $request->file('photo');
            $validated['photo_mime'] = $f->getMimeType();
            $validated['photo_data'] = base64_encode($f->get());
        }
        unset($validated['photo']);

        try {
            $visitor = $this->visitorService->checkIn($validated);

            // رمز QR يُرسم في المتصفح (qrcodejs) من qr_content — لا حزمة خادم
            $qrData = json_encode($visitor->generateQrData());
            $qrImage = null;

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل دخول الزائر',
                'data' => [
                    'visitor_id' => $visitor->id,
                    'badge_number' => $visitor->badge_number,
                    'qr_token' => $visitor->qr_token,
                    'qr_content' => $qrData,
                    'qr_image' => $qrImage,
                    'checked_in_at' => $visitor->checked_in_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل تسجيل الزائر: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check out a visitor
     */
    public function checkOut(EmergencyVisitor $visitor): JsonResponse
    {
        if (!$visitor->isCurrentlyIn()) {
            return response()->json([
                'success' => false,
                'message' => 'الزائر غادر مسبقاً',
            ], 422);
        }

        $this->visitorService->checkOut($visitor);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل خروج الزائر',
            'data' => [
                'checked_out_at' => $visitor->fresh()->checked_out_at->toIso8601String(),
                'duration' => $visitor->getDurationFormatted(),
            ],
        ]);
    }

    /**
     * Check out by QR token
     */
    public function checkOutByQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => 'required|string',
        ]);

        $visitor = $this->visitorService->getByQrToken($validated['qr_token']);

        if (!$visitor) {
            return response()->json([
                'success' => false,
                'message' => 'رمز QR غير صالح',
            ], 404);
        }

        if (!$visitor->isCurrentlyIn()) {
            return response()->json([
                'success' => false,
                'message' => 'الزائر غادر مسبقاً',
            ], 422);
        }

        $this->visitorService->checkOut($visitor);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل خروج الزائر',
            'data' => [
                'visitor_name' => $visitor->name,
                'duration' => $visitor->getDurationFormatted(),
            ],
        ]);
    }

    /**
     * Get visitors currently in a building
     */
    public function inBuilding(EmergencyBuilding $building): JsonResponse
    {
        $visitors = $this->visitorService->getVisitorsInBuilding($building->id);

        return response()->json([
            'success' => true,
            'data' => $visitors->map(fn($v) => $this->formatVisitor($v)),
            'count' => $visitors->count(),
        ]);
    }

    /**
     * Get today's visitors for a building
     */
    public function todayVisitors(EmergencyBuilding $building): JsonResponse
    {
        $visitors = $this->visitorService->getTodayVisitors($building->id);

        return response()->json([
            'success' => true,
            'data' => $visitors->map(fn($v) => $this->formatVisitor($v)),
            'count' => $visitors->count(),
        ]);
    }

    /**
     * Get visitor details
     */
    public function show(EmergencyVisitor $visitor): JsonResponse
    {
        $visitor->load(['building', 'host', 'assemblyPoint']);

        return response()->json([
            'success' => true,
            'data' => $this->formatVisitor($visitor, true),
        ]);
    }

    /**
     * Get visitor by QR token
     */
    public function getByQr(string $token): JsonResponse
    {
        $visitor = $this->visitorService->getByQrToken($token);

        if (!$visitor) {
            return response()->json([
                'success' => false,
                'message' => 'رمز QR غير صالح',
            ], 404);
        }

        $visitor->load(['building', 'host']);

        return response()->json([
            'success' => true,
            'data' => $this->formatVisitor($visitor),
        ]);
    }

    /**
     * Mark visitor safe during evacuation
     */
    public function markSafe(Request $request, EmergencyVisitor $visitor): JsonResponse
    {
        $validated = $request->validate([
            'assembly_point_id' => 'nullable|exists:assembly_points,id',
        ]);

        $assemblyPoint = null;
        if (!empty($validated['assembly_point_id'])) {
            $assemblyPoint = AssemblyPoint::find($validated['assembly_point_id']);
        }

        $this->visitorService->markVisitorSafe($visitor, $assemblyPoint);

        return response()->json([
            'success' => true,
            'message' => 'تم تأكيد سلامة الزائر',
        ]);
    }

    /**
     * Mark visitor as needing help
     */
    public function markNeedHelp(Request $request, EmergencyVisitor $visitor): JsonResponse
    {
        $validated = $request->validate([
            'location' => 'nullable|string|max:200',
        ]);

        $this->visitorService->markVisitorNeedHelp($visitor, $validated['location'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل طلب المساعدة',
        ]);
    }

    /**
     * Get evacuation stats for visitors in a building
     */
    public function evacuationStats(EmergencyBuilding $building): JsonResponse
    {
        $stats = $this->visitorService->getEvacuationStats($building->id);
        $needHelp = $this->visitorService->getVisitorsNeedingHelp($building->id);
        $missing = $this->visitorService->getMissingVisitors($building->id);

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'need_help' => $needHelp->map(fn($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'phone' => $v->phone,
                    'assistance_type' => $v->getAssistanceTypeLabel(),
                    'location' => $v->evacuation_location,
                ]),
                'missing' => $missing->map(fn($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'phone' => $v->phone,
                    'company' => $v->company,
                    'checked_in_at' => $v->checked_in_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * Search visitors
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'building_id' => 'nullable|exists:emergency_buildings,id',
        ]);

        $visitors = $this->visitorService->search(
            $validated['q'],
            $validated['building_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $visitors->map(fn($v) => $this->formatVisitor($v)),
            'count' => $visitors->count(),
        ]);
    }

    /**
     * Get visitor statistics
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $stats = $this->visitorService->getStats($period);

        return response()->json([
            'success' => true,
            'data' => $stats,
            'period' => $period,
        ]);
    }

    /**
     * Get visitor's QR code
     */
    public function qrCode(EmergencyVisitor $visitor): JsonResponse
    {
        $qrData = json_encode($visitor->generateQrData());

        return response()->json([
            'success' => true,
            'data' => [
                'qr_token' => $visitor->qr_token,
                'qr_content' => $qrData,
                'qr_image' => null,
            ],
        ]);
    }

    /**
     * Format visitor for response
     */
    protected function formatVisitor(EmergencyVisitor $visitor, bool $detailed = false): array
    {
        $data = [
            'id' => $visitor->id,
            'name' => $visitor->name,
            'phone' => $visitor->phone,
            'company' => $visitor->company,
            'badge_number' => $visitor->badge_number,
            'status' => $visitor->status,
            'status_label' => $visitor->getStatusLabel(),
            'status_color' => $visitor->getStatusColor(),
            'is_currently_in' => $visitor->isCurrentlyIn(),
            'building' => $visitor->building ? [
                'id' => $visitor->building_id,
                'name' => $visitor->building->name,
            ] : null,
            'host' => $visitor->host ? [
                'id' => $visitor->host_user_id,
                'name' => $visitor->host->name,
            ] : null,
            'checked_in_at' => $visitor->checked_in_at->toIso8601String(),
            'checked_out_at' => $visitor->checked_out_at?->toIso8601String(),
            'duration' => $visitor->getDurationFormatted(),
            'evacuation_status' => $visitor->evacuation_status,
            'evacuation_status_label' => $visitor->getEvacuationStatusLabel(),
            'evacuation_status_color' => $visitor->getEvacuationStatusColor(),
            'needs_assistance' => $visitor->needs_assistance,
            'assistance_type' => $visitor->getAssistanceTypeLabel(),
        ];

        if ($detailed) {
            $data = array_merge($data, [
                'email' => $visitor->email,
                'id_number' => $visitor->id_number,
                'id_type' => $visitor->id_type,
                'id_type_label' => $visitor->getIdTypeLabel(),
                'purpose' => $visitor->purpose,
                'vehicle_plate' => $visitor->vehicle_plate,
                'expected_checkout_at' => $visitor->expected_checkout_at?->toIso8601String(),
                'special_notes' => $visitor->special_notes,
                'qr_token' => $visitor->qr_token,
                'evacuation_location' => $visitor->evacuation_location,
                'evacuation_checked_at' => $visitor->evacuation_checked_at?->toIso8601String(),
                'assembly_point' => $visitor->assemblyPoint ? [
                    'id' => $visitor->evacuation_assembly_point_id,
                    'name' => $visitor->assemblyPoint->name,
                ] : null,
                'has_photo' => (bool) $visitor->photo_data,
            ]);
        }

        return $data;
    }

    /** صورة الزائر من القاعدة. */
    public function photo(EmergencyVisitor $visitor)
    {
        if (!$visitor->photo_data) {
            return response()->json(['success' => false, 'message' => 'لا توجد صورة'], 404);
        }
        return response(base64_decode($visitor->photo_data), 200, ['Content-Type' => $visitor->photo_mime ?: 'image/jpeg']);
    }
}
