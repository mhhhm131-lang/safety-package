<?php

namespace App\Modules\Form\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تعبئة واحدة لنموذج. مرتبطة بالتكليف الذي جاءت عنه (إن وُجد) فيُعرف من عبّأ عمّاذا.
 * `submitted_at` عمود حقيقي هنا — في OHSMS كان النموذج يكتبه ولا عمود له فتفشل كل تعبئة.
 */
class FormSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'form_id', 'assignment_id', 'submitted_by_id', 'submitted_at', 'submitted_ip', 'created_at',
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
        });
    }

    public function form(): BelongsTo        { return $this->belongsTo(FormTemplate::class, 'form_id'); }
    public function assignment(): BelongsTo  { return $this->belongsTo(FormAssignment::class, 'assignment_id'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_id'); }
    public function answers(): HasMany       { return $this->hasMany(FormAnswer::class, 'submission_id'); }
}
