<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyMedicalProfile extends Model
{

    protected $fillable = [
        'user_id',
        'blood_type',
        'allergies',
        'chronic_conditions',
        'current_medications',
        'has_pacemaker',
        'has_hearing_aid',
        'wears_glasses',
        'uses_wheelchair',
        'uses_cane_walker',
        'mobility_level',
        'needs_evacuation_assistance',
        'assistance_requirements',
        'emergency_contact_1_name',
        'emergency_contact_1_phone',
        'emergency_contact_1_relation',
        'emergency_contact_2_name',
        'emergency_contact_2_phone',
        'emergency_contact_2_relation',
        'primary_physician_name',
        'primary_physician_phone',
        'preferred_hospital',
        'insurance_provider',
        'insurance_policy_number',
        'medical_notes',
        'evacuation_instructions',
        'dnr_status',
        'is_verified',
        'verified_at',
        'verified_by_id',
        'share_with_responders',
        'share_with_medical_team',
        'last_reviewed_at',
        'last_reviewed_by_id',
    ];

    protected $casts = [
        'allergies' => 'array',
        'chronic_conditions' => 'array',
        'current_medications' => 'array',
        'has_pacemaker' => 'boolean',
        'has_hearing_aid' => 'boolean',
        'wears_glasses' => 'boolean',
        'uses_wheelchair' => 'boolean',
        'uses_cane_walker' => 'boolean',
        'needs_evacuation_assistance' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'share_with_responders' => 'boolean',
        'share_with_medical_team' => 'boolean',
        'last_reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'mobility_level' => 'full',
        'needs_evacuation_assistance' => false,
        'share_with_responders' => true,
        'share_with_medical_team' => true,
    ];

    // Blood type constants
    const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    // Mobility levels
    const MOBILITY_FULL = 'full';
    const MOBILITY_LIMITED = 'limited';
    const MOBILITY_WHEELCHAIR = 'wheelchair';
    const MOBILITY_BEDRIDDEN = 'bedridden';

    // Common allergies
    const COMMON_ALLERGIES = [
        'penicillin' => 'البنسلين',
        'aspirin' => 'الأسبرين',
        'ibuprofen' => 'الإيبوبروفين',
        'sulfa' => 'أدوية السلفا',
        'latex' => 'اللاتكس',
        'bee_stings' => 'لسعات النحل',
        'peanuts' => 'الفول السوداني',
        'shellfish' => 'المأكولات البحرية',
        'eggs' => 'البيض',
        'milk' => 'الحليب',
        'wheat' => 'القمح',
        'soy' => 'الصويا',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_id');
    }

    // Scopes
    public function scopeNeedsAssistance($query)
    {
        return $query->where('needs_evacuation_assistance', true);
    }

    public function scopeWithBloodType($query, string $bloodType)
    {
        return $query->where('blood_type', $bloodType);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeShareable($query)
    {
        return $query->where('share_with_responders', true);
    }

    // Helpers
    public function hasAllergies(): bool
    {
        return !empty($this->allergies);
    }

    public function hasChronicConditions(): bool
    {
        return !empty($this->chronic_conditions);
    }

    public function hasMedications(): bool
    {
        return !empty($this->current_medications);
    }

    public function hasMedicalDevices(): bool
    {
        return $this->has_pacemaker || $this->has_hearing_aid;
    }

    public function hasMobilityAids(): bool
    {
        return $this->uses_wheelchair || $this->uses_cane_walker;
    }

    public function requiresSpecialEvacuation(): bool
    {
        return $this->needs_evacuation_assistance
            || $this->mobility_level !== self::MOBILITY_FULL
            || $this->uses_wheelchair;
    }

    public function getPrimaryEmergencyContact(): ?array
    {
        if (!$this->emergency_contact_1_name) {
            return null;
        }

        return [
            'name' => $this->emergency_contact_1_name,
            'phone' => $this->emergency_contact_1_phone,
            'relation' => $this->emergency_contact_1_relation,
        ];
    }

    public function getSecondaryEmergencyContact(): ?array
    {
        if (!$this->emergency_contact_2_name) {
            return null;
        }

        return [
            'name' => $this->emergency_contact_2_name,
            'phone' => $this->emergency_contact_2_phone,
            'relation' => $this->emergency_contact_2_relation,
        ];
    }

    public function getAllEmergencyContacts(): array
    {
        $contacts = [];

        if ($primary = $this->getPrimaryEmergencyContact()) {
            $contacts[] = $primary;
        }

        if ($secondary = $this->getSecondaryEmergencyContact()) {
            $contacts[] = $secondary;
        }

        return $contacts;
    }

    public function getMobilityLevelLabel(): string
    {
        return match($this->mobility_level) {
            'full' => 'كاملة',
            'limited' => 'محدودة',
            'wheelchair' => 'كرسي متحرك',
            'bedridden' => 'طريح الفراش',
            default => $this->mobility_level,
        };
    }

    public function getAllergiesFormatted(): string
    {
        if (!$this->allergies) {
            return 'لا يوجد';
        }

        return collect($this->allergies)->map(function ($allergy) {
            return self::COMMON_ALLERGIES[$allergy] ?? $allergy;
        })->implode(', ');
    }

    public function getEmergencyCardData(): array
    {
        return [
            'user_name' => $this->user?->name,
            'blood_type' => $this->blood_type,
            'allergies' => $this->allergies,
            'chronic_conditions' => $this->chronic_conditions,
            'medications' => $this->current_medications,
            'has_pacemaker' => $this->has_pacemaker,
            'mobility_level' => $this->mobility_level,
            'needs_assistance' => $this->needs_evacuation_assistance,
            'assistance_requirements' => $this->assistance_requirements,
            'emergency_contact' => $this->getPrimaryEmergencyContact(),
            'medical_notes' => $this->medical_notes,
            'evacuation_instructions' => $this->evacuation_instructions,
        ];
    }

    public function getQuickInfoForResponders(): array
    {
        if (!$this->share_with_responders) {
            return ['restricted' => true];
        }

        return [
            'blood_type' => $this->blood_type,
            'allergies' => $this->allergies,
            'has_pacemaker' => $this->has_pacemaker,
            'mobility_level' => $this->mobility_level,
            'needs_assistance' => $this->needs_evacuation_assistance,
            'medical_notes' => $this->medical_notes,
            'emergency_contact' => $this->getPrimaryEmergencyContact(),
        ];
    }

    public function verify(int $userId): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by_id' => $userId,
        ]);
    }

    public function markReviewed(int $userId): void
    {
        $this->update([
            'last_reviewed_at' => now(),
            'last_reviewed_by_id' => $userId,
        ]);
    }
}
