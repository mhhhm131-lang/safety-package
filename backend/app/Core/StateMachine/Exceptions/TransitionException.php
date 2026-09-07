<?php

namespace App\Core\StateMachine\Exceptions;

use Exception;

class TransitionException extends Exception
{
    protected string $fromStatus;
    protected string $toStatus;

    public function __construct(string $message = '', string $from = '', string $to = '')
    {
        parent::__construct($message);
        $this->fromStatus = $from;
        $this->toStatus = $to;
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }
}
