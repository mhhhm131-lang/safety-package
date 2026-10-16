<?php

namespace App\Modules\Form\Models;

use App\Core\Traits\BelongsToBuilding;
use App\Models\User;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ٢٩-٤ (قرار ٨٨): جولة بطاقة الإدارة لإدارة واحدة.
 *   لدى الإدارة للتعبئة (sent) ← اعتمدها المدير «البيانات صحيحة وأعتمدها» (done) ← الإقرارات جارية (ack، ٢٩-٥) ← مقفلة (closed، ٢٩-٦).
 * التوقيع اسم ووقت: كل خطوة في `log` بمن فعلها ومتى (قرار ٨٧-٦). تحمل مبناها من فرع الوحدة (`BelongsToBuilding`، قرار ٨٤)
 * وتُقرأ بالوحدة في `FormVisibilityService`. لا جولة فوق جولة مفتوحة للوحدة نفسها.
 */
class FormRound extends Model
{
    use BelongsToBuilding;

    public const STATE_SENT   = 'sent';
    public const STATE_DONE   = 'done';
    public const STATE_ACK    = 'ack';
    public const STATE_CLOSED = 'closed';

    public const STATE_LABELS = [
        self::STATE_SENT   => 'لدى الإدارة للتعبئة',
        self::STATE_DONE   => 'اعتمدها المدير',
        self::STATE_ACK    => 'الإقرارات جارية',
        self::STATE_CLOSED => 'مقفلة',
    ];

    public const ACTION_LABELS = [
        'opened'   => 'فُتحت الجولة وكُلّف المدير',
        'approved' => 'اعتمد المدير: «البيانات صحيحة وأعتمدها»',
    ];

    protected $fillable = ['organization_unit_id', 'building_id', 'round_no', 'state', 'opened_by_id', 'approved_by_id', 'approved_at', 'log'];

    protected $casts = ['log' => 'array', 'approved_at' => 'datetime', 'round_no' => 'integer'];

    protected $attributes = ['state' => self::STATE_SENT, 'round_no' => 1];

    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function openedBy(): BelongsTo         { return $this->belongsTo(User::class, 'opened_by_id'); }
    public function approvedBy(): BelongsTo       { return $this->belongsTo(User::class, 'approved_by_id'); }
    public function assignments(): HasMany        { return $this->hasMany(FormAssignment::class, 'round_id'); }
    public function submissions(): HasMany        { return $this->hasMany(FormSubmission::class, 'round_id'); }

    public function getStateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? $this->state;
    }

    public function stateColor(): string
    {
        return match ($this->state) {
            self::STATE_SENT   => 'warning text-dark',
            self::STATE_DONE   => 'primary',
            self::STATE_ACK    => 'info text-dark',
            default            => 'secondary',
        };
    }

    public function isOpen(): bool
    {
        return $this->state !== self::STATE_CLOSED;
    }

    /** خطوة في السجل بالاسم والوقت — لا تحفظ؛ من يستدعيها يحفظ. */
    public function addLog(string $action, ?User $by, ?string $note = null): void
    {
        $log = $this->log ?? [];
        $log[] = ['action' => $action, 'by_id' => $by?->id, 'by' => $by?->name, 'at' => now()->format('Y-m-d H:i'), 'note' => $note];
        $this->log = $log;
    }

    /** تكليف مدير الإدارة ببطاقة الإدارة في هذه الجولة (في أي مبنى — الجولة نفسها معزولة بمبناها). */
    public function cardAssignment(): ?FormAssignment
    {
        return FormAssignment::withoutGlobalScope('building')->where('round_id', $this->id)
            ->whereHas('form', fn ($q) => $q->where('package_code', NonMaterialPackage::CODE))
            ->with(['assignedTo', 'form'])
            ->first();
    }
}
