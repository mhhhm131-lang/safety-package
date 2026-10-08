<?php

namespace App\Modules\Risk\Models;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خطة المعالج — الخطوة ٦ (بكلمته ٢٠٢٦-١٠-٠٧ «الأطوار الثلاثة في سجل المخاطر تلغى ويؤخذ محتواها… بلا قبل أثناء بعد»):
 * الخطر صف واحد من الأسباب والمتأثرين والإجراء الوقائي والتصحيحي والتقييم المتبقي ومن يطبّق الضوابط (نص الكتاب).
 * كان من OHSMS ثلاثة أطوار (استباقي/تشغيلي/استجابة) لكل خطر؛ دُمج محتواها في صف واحد مفتاحه `single`
 * (الترحيل `2026_10_08_100003` للقائم، و`Support\PhaseMerger` للبذر من ملفات الكتاب التي بقيت بأطوارها الثلاثة).
 * المفاتيح القديمة باقية للدمج والترحيل فقط، لا تُنشأ بها صفوف جديدة.
 *
 * Responsibility is dual-stored: an FK to the hierarchy/users plus a free-text fallback (نص الكتاب «من يطبّق الضوابط»).
 */
class RiskPhase extends Model
{
    public const PHASE_SINGLE = 'single'; // الصف الواحد

    /** مفاتيح OHSMS القديمة — للترحيل والدمج من ملفات الكتاب فقط */
    public const PHASE_PROACTIVE   = 'proactive';   // استباقي
    public const PHASE_OPERATIONAL = 'operational'; // تشغيلي
    public const PHASE_RESPONSE    = 'response';    // استجابة
    public const LEGACY_PHASES = [self::PHASE_PROACTIVE, self::PHASE_OPERATIONAL, self::PHASE_RESPONSE];

    public const PHASES = [self::PHASE_SINGLE];

    public const PHASE_LABELS = [
        self::PHASE_SINGLE      => 'الإجراءات',
        self::PHASE_PROACTIVE   => 'استباقي',
        self::PHASE_OPERATIONAL => 'تشغيلي',
        self::PHASE_RESPONSE    => 'استجابة',
    ];

    protected $fillable = [
        'risk_id',
        'phase',
        'preventive_action',
        'corrective_action',
        'residual_assessment',
        'responsible_org_unit_id',
        'responsible_org_unit_text',
        'responsible_user_id',
        'responsible_user_text',
        'notes',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function responsibleOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'responsible_org_unit_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function causes(): BelongsToMany
    {
        return $this->belongsToMany(RiskCause::class, 'risk_phase_causes');
    }

    public function affectedGroups(): BelongsToMany
    {
        return $this->belongsToMany(AffectedGroup::class, 'risk_phase_affected_groups');
    }

    public function affectedGroupDetails(): HasMany
    {
        return $this->hasMany(RiskPhaseAffectedGroupDetail::class);
    }

    public function getPhaseLabelAttribute(): string
    {
        return self::PHASE_LABELS[$this->phase] ?? $this->phase;
    }

    /** Prefers the linked org unit's name; falls back to the free-text suggestion. */
    public function getResponsibleOrgUnitDisplayAttribute(): ?string
    {
        return $this->responsibleOrgUnit?->name ?? $this->responsible_org_unit_text;
    }

    /** Prefers the linked user's name; falls back to the free-text suggestion. */
    public function getResponsibleUserDisplayAttribute(): ?string
    {
        return $this->responsibleUser?->name ?? $this->responsible_user_text;
    }
}
