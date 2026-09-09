<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use App\Modules\Risk\Models\RiskControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/**
 * بند واحد في قائمة تحقق التصريح.
 * يولّده SmartJsaService من بنود التحكم (المخاطر)، أو QualificationChecklistService من كتالوج التأهيل.
 * لا يُفعَّل التصريح حتى تكتمل بنوده الإلزامية (Permit::requirementsSatisfied).
 */
class PermitRequirement extends Model
{
    public const CATEGORY_DOCUMENT            = 'document';
    public const CATEGORY_TRAINING            = 'training';
    public const CATEGORY_CERTIFICATE         = 'certificate';
    public const CATEGORY_RISK_CONTROL        = 'risk_control';
    public const CATEGORY_PREREQUISITE_PERMIT = 'prerequisite_permit';
    public const CATEGORY_WORKER_CHECK        = 'worker_check';
    public const CATEGORY_EQUIPMENT_CHECK     = 'equipment_check';
    public const CATEGORY_QUALIFICATION       = 'qualification';
    public const CATEGORY_OTHER               = 'other';

    public const STATUS_REQUIRED    = 'required';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_WAIVED      = 'waived';
    public const STATUS_FAILED      = 'failed';

    public const SEVERITY_MANDATORY   = 'mandatory';
    public const SEVERITY_RECOMMENDED = 'recommended';

    public const PHASE_LABELS = [
        'preventive'  => 'استباقي',
        'operational' => 'تشغيلي',
        'response'    => 'استجابة',
    ];

    protected $fillable = [
        'permit_id', 'category', 'requirement_code', 'description_ar', 'reference_id', 'risk_control_id',
        'phase', 'evidence_type', 'responsible_role', 'frequency', 'status', 'severity',
        'evidence_value', 'measurement_passed', 'evidence_file', 'evidence_file_mime', 'evidence_file_data',
        'completed_by_id', 'completed_at', 'verified_by_id', 'verified_at', 'notes',
    ];

    protected $hidden = ['evidence_file_data'];

    protected $casts = [
        'completed_at'       => 'datetime',
        'verified_at'        => 'datetime',
        'measurement_passed' => 'boolean',
    ];

    protected $attributes = [
        'status'   => self::STATUS_REQUIRED,
        'severity' => self::SEVERITY_MANDATORY,
    ];

    public function permit(): BelongsTo      { return $this->belongsTo(Permit::class); }
    public function riskControl(): BelongsTo { return $this->belongsTo(RiskControl::class); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by_id'); }
    public function verifiedBy(): BelongsTo  { return $this->belongsTo(User::class, 'verified_by_id'); }

    public function phaseLabel(): string
    {
        return self::PHASE_LABELS[$this->phase] ?? ($this->phase ?? '');
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_WAIVED], true);
    }

    /** نص البند كما يُعرض: الوصف المخزَّن، أو وصف بند التحكم، أو الرمز مفسَّراً. */
    public function label(): string
    {
        if ($this->description_ar) {
            return $this->description_ar;
        }
        if ($this->riskControl?->description_ar) {
            return $this->riskControl->description_ar;
        }
        $code = $this->requirement_code;
        if (str_starts_with($code, 'training:topic_')) {
            $topic = \App\Modules\Worker\Models\TrainingTopic::find((int) substr($code, 15));
            return 'تدريب: '.($topic?->name ?? substr($code, 15));
        }
        if (str_starts_with($code, 'document:')) {
            return 'وثيقة: '.self::documentLabel(substr($code, 9));
        }
        return $code;
    }

    /** أسماء الوثائق القياسية بالعربية (رموزها إنجليزية في المولّد). */
    public static function documentLabel(string $key): string
    {
        return [
            'commercial_registration'        => 'السجل التجاري',
            'vat_certificate'                => 'شهادة الضريبة',
            'insurance_liability'            => 'وثيقة التأمين',
            'hse_policy'                     => 'سياسة السلامة والصحة والبيئة',
            'iso_45001'                      => 'شهادة ISO 45001',
            'tenant_hse_policy_acknowledged' => 'الإقرار بسياسة السلامة في المعهد',
        ][$key] ?? $key;
    }

    /** الدليل المرفوع base64 في القاعدة (قرص Render مؤقت). */
    public function attachEvidence(UploadedFile $file): void
    {
        $this->evidence_file      = $file->getClientOriginalName();
        $this->evidence_file_mime = $file->getMimeType();
        $content = (string) file_get_contents($file->getRealPath());
        $this->evidence_file_data = $content === '' ? null : base64_encode($content);
    }

    public function hasEvidenceFile(): bool
    {
        return !empty($this->evidence_file_data);
    }
}
