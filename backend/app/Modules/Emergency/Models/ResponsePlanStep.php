<?php

namespace App\Modules\Emergency\Models;

use App\Modules\Emergency\Support\RoleCards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** خطوة في خطة استجابة مكان — متى · من · أين · كيف كما في الوثيقة، مع نافذة بالثواني وبطاقة الدور. */
class ResponsePlanStep extends Model
{
    protected $fillable = ['plan_id', 'path_key', 'path_title', 'path_declared_count', 'sort', 'label', 'title',
        'when_text', 'window_from_sec', 'window_to_sec', 'is_conditional', 'who_text', 'where_text', 'how_text',
        'role_cards', 'role_card_no'];

    protected $casts = ['role_cards' => 'array', 'is_conditional' => 'boolean', 'sort' => 'integer',
        'window_from_sec' => 'integer', 'window_to_sec' => 'integer', 'role_card_no' => 'integer',
        'path_declared_count' => 'integer'];

    public function plan(): BelongsTo { return $this->belongsTo(ResponsePlan::class, 'plan_id'); }

    public function isLive(): bool { return in_array($this->path_key, ResponsePlan::LIVE_PATHS, true); }

    public function hasCard(): bool { return $this->role_card_no !== null; }

    /** البطاقات المطابقة كمصفوفة [رقم => بيانات البطاقة]. */
    public function cards(): array
    {
        $out = [];
        foreach ($this->role_cards ?? [] as $n) if ($c = RoleCards::get((int) $n)) $out[(int) $n] = $c;
        return $out;
    }

    public function primaryCard(): ?array { return $this->role_card_no ? RoleCards::get($this->role_card_no) : null; }

    /** النافذة بصيغة مقروءة: «٠–٥ ث» أو «شرطية». */
    public function windowLabel(): string
    {
        if ($this->window_from_sec === null || $this->window_to_sec === null) return $this->is_conditional ? 'شرطية' : '—';
        $fmt = function (int $s): string {
            if ($s >= 3600 && $s % 3600 === 0) return ($s / 3600).' س';
            if ($s >= 60 && $s % 60 === 0) return ($s / 60).' د';
            return $s.' ث';
        };
        return $this->window_from_sec === 0 && $this->window_to_sec === 5 ? 'الثانية الأولى (٠–٥ ث)' : $fmt($this->window_from_sec).' – '.$fmt($this->window_to_sec);
    }
}
