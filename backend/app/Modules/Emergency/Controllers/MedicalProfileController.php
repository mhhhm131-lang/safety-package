<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyMedicalProfile;
use App\Modules\Emergency\Services\MedicalProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicalProfileController extends Controller
{
    public function __construct(
        protected MedicalProfileService $medicalService
    ) {}

    /**
     * Get current user's medical profile
     */
    public function myProfile(): JsonResponse
    {
        $profile = $this->medicalService->getOrCreate(auth()->id());
        $profile->load('user');

        return response()->json([
            'success' => true,
            'data' => $this->formatProfile($profile),
        ]);
    }

    /**
     * Update current user's medical profile
     */
    public function updateMyProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blood_type' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'allergies' => 'nullable|array',
            'allergies.*' => 'string|max:100',
            'chronic_conditions' => 'nullable|array',
            'chronic_conditions.*' => 'string|max:200',
            'current_medications' => 'nullable|array',
            'current_medications.*' => 'string|max:200',
            'has_pacemaker' => 'nullable|boolean',
            'has_hearing_aid' => 'nullable|boolean',
            'wears_glasses' => 'nullable|boolean',
            'uses_wheelchair' => 'nullable|boolean',
            'uses_cane_walker' => 'nullable|boolean',
            'mobility_level' => 'nullable|in:full,limited,wheelchair,bedridden',
            'needs_evacuation_assistance' => 'nullable|boolean',
            'assistance_requirements' => 'nullable|string|max:500',
            'emergency_contact_1_name' => 'nullable|string|max:100',
            'emergency_contact_1_phone' => 'nullable|string|max:20',
            'emergency_contact_1_relation' => 'nullable|string|max:50',
            'emergency_contact_2_name' => 'nullable|string|max:100',
            'emergency_contact_2_phone' => 'nullable|string|max:20',
            'emergency_contact_2_relation' => 'nullable|string|max:50',
            'primary_physician_name' => 'nullable|string|max:100',
            'primary_physician_phone' => 'nullable|string|max:20',
            'preferred_hospital' => 'nullable|string|max:200',
            'insurance_provider' => 'nullable|string|max:100',
            'insurance_policy_number' => 'nullable|string|max:50',
            'medical_notes' => 'nullable|string|max:1000',
            'evacuation_instructions' => 'nullable|string|max:500',
            'share_with_responders' => 'nullable|boolean',
            'share_with_medical_team' => 'nullable|boolean',
        ]);

        $profile = $this->medicalService->getOrCreate(auth()->id());
        $profile = $this->medicalService->update($profile, $validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الطبي',
            'data' => $this->formatProfile($profile),
        ]);
    }

    /**
     * Get a user's medical profile (for authorized personnel)
     */
    public function show(int $userId): JsonResponse
    {
        $profile = EmergencyMedicalProfile::where('user_id', $userId)
            ->with('user')
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد ملف طبي لهذا المستخدم',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatProfile($profile),
        ]);
    }

    /**
     * Get medical info for emergency responders (limited data)
     */
    public function forResponders(int $userId): JsonResponse
    {
        $info = $this->medicalService->getForResponders($userId);

        if (!$info) {
            return response()->json([
                'success' => false,
                'message' => 'لا تتوفر معلومات طبية أو المستخدم لم يسمح بمشاركتها',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $info,
        ]);
    }

    /**
     * Get all users needing evacuation assistance
     */
    public function needsAssistance(): JsonResponse
    {
        $users = $this->medicalService->getUsersNeedingAssistance();

        return response()->json([
            'success' => true,
            'data' => $users,
            'count' => $users->count(),
        ]);
    }

    /**
     * Get critical medical information for emergency response
     */
    public function criticalInfo(): JsonResponse
    {
        $profiles = $this->medicalService->getCriticalMedicalInfo();

        return response()->json([
            'success' => true,
            'data' => $profiles->map(fn($p) => [
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name,
                'has_pacemaker' => $p->has_pacemaker,
                'allergies' => $p->allergies,
                'mobility_level' => $p->mobility_level,
                'medical_notes' => $p->medical_notes,
            ]),
            'count' => $profiles->count(),
        ]);
    }

    /**
     * Get users by blood type
     */
    public function byBloodType(string $bloodType): JsonResponse
    {
        if (!in_array($bloodType, EmergencyMedicalProfile::BLOOD_TYPES)) {
            return response()->json([
                'success' => false,
                'message' => 'فصيلة دم غير صحيحة',
            ], 422);
        }

        $users = $this->medicalService->getUsersByBloodType($bloodType);

        return response()->json([
            'success' => true,
            'data' => $users->map(fn($p) => [
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name,
                'phone' => $p->user?->phone,
            ]),
            'count' => $users->count(),
            'blood_type' => $bloodType,
        ]);
    }

    /**
     * Get medical profile statistics
     */
    public function stats(): JsonResponse
    {
        $stats = $this->medicalService->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Verify a medical profile
     */
    public function verify(EmergencyMedicalProfile $profile): JsonResponse
    {
        $this->medicalService->verify($profile, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'تم التحقق من الملف الطبي',
        ]);
    }

    /**
     * Mark profile as reviewed
     */
    public function markReviewed(EmergencyMedicalProfile $profile): JsonResponse
    {
        $this->medicalService->markReviewed($profile, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'تم مراجعة الملف الطبي',
        ]);
    }

    /**
     * Get profiles needing review
     */
    public function needsReview(): JsonResponse
    {
        $profiles = $this->medicalService->getProfilesNeedingReview();

        return response()->json([
            'success' => true,
            'data' => $profiles->map(fn($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name,
                'last_reviewed_at' => $p->last_reviewed_at?->toIso8601String(),
                'is_verified' => $p->is_verified,
            ]),
            'count' => $profiles->count(),
        ]);
    }

    /**
     * Generate emergency card data
     */
    public function emergencyCard(): JsonResponse
    {
        $card = $this->medicalService->generateEmergencyCard(auth()->id());

        if (!$card) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم إنشاء ملف طبي بعد',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }

    /**
     * Format profile for response
     */
    protected function formatProfile(EmergencyMedicalProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'user_name' => $profile->user?->name,
            'blood_type' => $profile->blood_type,
            'allergies' => $profile->allergies,
            'allergies_formatted' => $profile->getAllergiesFormatted(),
            'chronic_conditions' => $profile->chronic_conditions,
            'current_medications' => $profile->current_medications,
            'has_pacemaker' => $profile->has_pacemaker,
            'has_hearing_aid' => $profile->has_hearing_aid,
            'wears_glasses' => $profile->wears_glasses,
            'uses_wheelchair' => $profile->uses_wheelchair,
            'uses_cane_walker' => $profile->uses_cane_walker,
            'mobility_level' => $profile->mobility_level,
            'mobility_level_label' => $profile->getMobilityLevelLabel(),
            'needs_evacuation_assistance' => $profile->needs_evacuation_assistance,
            'assistance_requirements' => $profile->assistance_requirements,
            'emergency_contact_1' => $profile->getPrimaryEmergencyContact(),
            'emergency_contact_2' => $profile->getSecondaryEmergencyContact(),
            'primary_physician_name' => $profile->primary_physician_name,
            'primary_physician_phone' => $profile->primary_physician_phone,
            'preferred_hospital' => $profile->preferred_hospital,
            'insurance_provider' => $profile->insurance_provider,
            'insurance_policy_number' => $profile->insurance_policy_number,
            'medical_notes' => $profile->medical_notes,
            'evacuation_instructions' => $profile->evacuation_instructions,
            'share_with_responders' => $profile->share_with_responders,
            'share_with_medical_team' => $profile->share_with_medical_team,
            'is_verified' => $profile->is_verified,
            'verified_at' => $profile->verified_at?->toIso8601String(),
            'last_reviewed_at' => $profile->last_reviewed_at?->toIso8601String(),
            'updated_at' => $profile->updated_at->toIso8601String(),
        ];
    }
}
