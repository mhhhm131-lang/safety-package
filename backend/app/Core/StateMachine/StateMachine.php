<?php

namespace App\Core\StateMachine;

use App\Core\StateMachine\Exceptions\TransitionException;

class StateMachine
{
    protected array $transitions;

    public function __construct(array $transitions)
    {
        $this->transitions = $transitions;
    }

    public function validate(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new TransitionException(
                "Cannot transition from '{$from}' to '{$to}'"
            );
        }
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
