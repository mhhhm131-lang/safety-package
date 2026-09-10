<?php

namespace App\Modules\Emergency\Models;

use App\Modules\Governance\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خطة استجابة مكان — مشتقة من `HZ-0x/response-plan.html` (المرحلة ١٠-١، المكوّن أ). لا تُحرَّر هنا.
 */
class ResponsePlan extends Model
{
    protected $fillable = ['place_id', 'title', 'declared_total', 'steps_count', 'detection_count', 'scenario_count',
        'no_card_count', 'source_path', 'fingerprint', 'synced_at'];

    protected $casts = ['synced_at' => 'datetime', 'declared_total' => 'integer', 'steps_count' => 'integer',
        'detection_count' => 'integer', 'scenario_count' => 'integer', 'no_card_count' => 'integer'];

    /** المسارات بترتيب الوثيقة وعناوينها العامة. */
    public const PATHS = [
        'detection' => 'الكشف والبلاغ (٠)',
        'scenario' => 'السيناريوهات',
        'medical' => 'المسار الطبي',
        'fire' => 'مسار المكان',
        'other' => 'حالات أخرى',
    ];

    /** المسارات التي تدخل القائمة الحية عند التفعيل (١٠-٢): كل ما هو خطوة مرقّمة بزمن. */
    public const LIVE_PATHS = ['medical', 'fire', 'other'];

    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function steps(): HasMany { return $this->hasMany(ResponsePlanStep::class, 'plan_id')->orderBy('sort'); }

    /** خطوات المسارات (بلا الكشف والسيناريوهات). */
    public function pathSteps()
    {
        return $this->steps->whereIn('path_key', self::LIVE_PATHS)->values();
    }

    /** الخطوات مجمّعة بالمسار بترتيب الوثيقة. */
    public function stepsByPath(): array
    {
        $out = [];
        foreach ($this->steps as $s) $out[$s->path_key][] = $s;
        return $out;
    }

    public function shortFingerprint(): string { return substr($this->fingerprint, 0, 8); }

    /**
     * بطاقات الأدوار في خطوات المسارات التي لا شاغل لها اليوم (المرحلة ١٠-٤، جاهزية المكان):
     * بطاقة بدور نظامي بلا حساب نشط في ذلك الدور، أو بطاقة فريق أولي بلا عضو بالمفتاح في فرق المكان.
     * @param  \Illuminate\Support\Collection $placeTeams  فرق المكان النشطة بأعضائها
     * @param  array<string,int> $activeByRole  عدد الحسابات النشطة لكل دور
     * @return int[] أرقام البطاقات
     */
    public function unstaffedCards($placeTeams, array $activeByRole): array
    {
        $memberKeys = $placeTeams->flatMap(fn ($t) => $t->members->pluck('role_key'))->unique()->all();
        $needed = [];
        foreach ($this->pathSteps() as $s) foreach ($s->role_cards ?? [] as $n) $needed[(int) $n] = true;
        $out = [];
        foreach (array_keys($needed) as $no) {
            $card = \App\Modules\Emergency\Support\RoleCards::get($no);
            if (!$card || !empty($card['none'])) continue;
            if (!empty($card['role']) && ($activeByRole[$card['role']] ?? 0) === 0) $out[] = $no;
            if (!empty($card['team']) && !array_intersect($card['team'], $memberKeys)) $out[] = $no;
        }
        sort($out);
        return $out;
    }

    public function documentUrl(): string
    {
        return '/'.(Place::FOLDERS[$this->place?->code] ?? '').'/response-plan.html';
    }
}
