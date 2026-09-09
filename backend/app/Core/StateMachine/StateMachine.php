<?php

namespace App\Core\StateMachine;

use App\Core\StateMachine\Exceptions\TransitionException;

class StateMachine
{
    protected array $transitions;

    /**
     * أسماء الحالات بالعربية: [المفتاح => الاسم]. تمرّرها كل آلة حالة من ثوابت نموذجها.
     *
     * **المرحلة ٨-٤:** كانت الرسالة إنجليزية بمفاتيح داخلية
     * («Cannot transition from 'field_received' to 'received'») تظهر كما هي للمستخدم العربي.
     * الآن تُقال بلغته وبأسماء الحالات التي يعرفها من الشاشة.
     */
    protected array $labels;

    public function __construct(array $transitions, array $labels = [])
    {
        $this->transitions = $transitions;
        $this->labels = $labels;
    }

    public function validate(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new TransitionException($this->refusalMessage($from, $to));
        }
    }

    /** رسالة الرفض: ما الحالة الآن، وما طُلب، وما المتاح فعلاً. */
    public function refusalMessage(string $from, string $to): string
    {
        $message = 'لا يصح الانتقال من «'.$this->label($from).'» إلى «'.$this->label($to).'».';

        $available = array_keys($this->getAvailable($from));
        if ($available) {
            $names = array_map(fn ($state) => '«'.$this->label($state).'»', $available);
            $message .= ' المتاح من هنا: '.implode('، ', $names).'.';
        } else {
            $message .= ' لا انتقال متاح من هذه الحالة.';
        }

        return $message;
    }

    public function label(string $state): string
    {
        return $this->labels[$state] ?? $state;
    }

    public function canTransition(string $from, string $to): bool
    {
        if (isset($this->transitions['any']) && in_array($to, array_keys($this->transitions['any']))) {
            return true;
        }

        return isset($this->transitions[$from][$to]);
    }

    public function getAvailable(string $from): array
    {
        $available = $this->transitions[$from] ?? [];

        if (isset($this->transitions['any'])) {
            $available = array_merge($available, $this->transitions['any']);
        }

        return $available;
    }

    public function getAllStatuses(): array
    {
        $statuses = [];
        foreach ($this->transitions as $from => $targets) {
            if ($from !== 'any') {
                $statuses[] = $from;
            }
            foreach ($targets as $to => $roles) {
                $statuses[] = $to;
            }
        }
        return array_unique($statuses);
    }

    public function getAllowedRoles(string $from, string $to): array
    {
        if (isset($this->transitions['any'][$to])) {
            return $this->transitions['any'][$to];
        }

        return $this->transitions[$from][$to] ?? [];
    }

    public function canUserTransition(string $from, string $to, string $role): bool
    {
        if (!$this->canTransition($from, $to)) {
            return false;
        }

        $allowedRoles = $this->getAllowedRoles($from, $to);
        return in_array($role, $allowedRoles) || in_array('any', $allowedRoles);
    }
}
