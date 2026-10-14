<?php

namespace App\Modules\Form\Models;

use App\Core\Traits\BelongsToBuilding;
use App\Models\User;
use App\Modules\Form\Support\OwnerScope;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تعبئة واحدة لنموذج. مرتبطة بالتكليف الذي جاءت عنه (إن وُجد) فيُعرف من عبّأ عمّاذا.
 * `submitted_at` عمود حقيقي هنا — في OHSMS كان النموذج يكتبه ولا عمود له فتفشل كل تعبئة.
 *
 * ٢٩-٠ (قرار ٨٧): تحمل إدارة المعبِّئ ومبناه (`OwnerScope`) فتُقرأ بالمبنى تلقائياً (`BelongsToBuilding`، قرار ٨٤)
 * وبالوحدة في `FormVisibilityService` — وتبقى القاعدة صادقة حين تُحذف نسبتها إلى شخص (السرية، ٢٩-٢).
 */
class FormSubmission extends Model
{
    use BelongsToBuilding;

    public const UPDATED_AT = null;

    protected $fillable = [
        'form_id', 'assignment_id', 'submitted_by_id', 'building_id', 'organization_unit_id', 'submitted_at', 'submitted_ip', 'created_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FormSubmission $s) {
            $s->submitted_at ??= now();
            $s->created_at ??= now();
            if ($s->building_id === null || $s->organization_unit_id === null) {
                $scope = OwnerScope::of($s->submitted_by_id ? (int) $s->submitted_by_id : null);
                $s->building_id ??= $scope['building_id'];
                $s->organization_unit_id ??= $scope['organization_unit_id'];
            }
        });
    }

    public function form(): BelongsTo        { return $this->belongsTo(FormTemplate::class, 'form_id'); }
    public function assignment(): BelongsTo  { return $this->belongsTo(FormAssignment::class, 'assignment_id'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_id'); }
    public function organizationUnit(): BelongsTo { return $this->belongsTo(OrganizationUnit::class); }
    public function answers(): HasMany       { return $this->hasMany(FormAnswer::class, 'submission_id'); }
}
