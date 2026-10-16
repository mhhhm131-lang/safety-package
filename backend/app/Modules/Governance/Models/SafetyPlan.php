<?php

namespace App\Modules\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خطة سلامة مكان في مبناه (المرحلة ٣٠-١، قرار ٨٩): صفوف جدولي الوثيقة `HZ-0x/safety-plan.html` مزروعة مرة ومعدَّلة من الشاشة.
 * الوثيقة تبقى أصلاً للمراجعة؛ النظام يقرأ الصفوف.
 */
class SafetyPlan extends Model
{
    protected $fillable = ['place_id', 'title', 'tasks_count', 'proactive_count', 'operational_count', 'edited_count',
        'source_path', 'fingerprint', 'synced_at'];

    protected $casts = ['synced_at' => 'datetime', 'tasks_count' => 'integer', 'proactive_count' => 'integer',
        'operational_count' => 'integer', 'edited_count' => 'integer'];

    public function place(): BelongsTo { return $this->belongsTo(Place::class); }

    public function tasks(): HasMany { return $this->hasMany(SafetyPlanTask::class, 'plan_id')->orderBy('sort'); }

    /** الصفوف مجمّعة بالطبقة بترتيب الوثيقة: الاستباقية ثم التشغيلية. */
    public function tasksByLayer(): array
    {
        $out = array_fill_keys(array_keys(SafetyPlanTask::LAYERS), []);
        foreach ($this->tasks as $t) $out[$t->layer][] = $t;
        return $out;
    }

    /** العدّادات من الصفوف (بعد كل زرع أو تعديل). */
    public function refreshCounts(): void
    {
        $tasks = SafetyPlanTask::where('plan_id', $this->id)->get();
        $this->forceFill([
            'tasks_count' => $tasks->count(),
            'proactive_count' => $tasks->where('layer', 'proactive')->count(),
            'operational_count' => $tasks->where('layer', 'operational')->count(),
            'edited_count' => $tasks->filter(fn ($t) => $t->isEdited())->count(),
        ])->save();
    }

    public function documentUrl(): string
    {
        return '/'.(Place::FOLDERS[$this->place?->category] ?? '').'/safety-plan.html'; // الوثيقة للصنف
    }

    public function shortFingerprint(): string { return substr($this->fingerprint, 0, 8); }
}
