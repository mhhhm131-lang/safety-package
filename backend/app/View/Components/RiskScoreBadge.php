<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** منقول من OHSMS كما هو: شارة درجة الخطر (≥١٥ أحمر، ≥٨ أصفر، غير ذلك أخضر). */
class RiskScoreBadge extends Component
{
    public string $color;

    public function __construct(
        public int $score = 0,
    ) {
        if ($score >= 15) {
            $this->color = 'danger';
        } elseif ($score >= 8) {
            $this->color = 'warning';
        } else {
            $this->color = 'success';
        }
    }

    public function render(): View|Closure|string
    {
        return view('components.risk-score-badge');
    }
}
