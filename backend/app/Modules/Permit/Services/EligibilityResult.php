<?php

namespace App\Modules\Permit\Services;

/** نتيجة فحص الأهلية: هل يمكن إصدار التصريح، وإن لا فلماذا (رسائل عربية تُعرض كما هي). */
final class EligibilityResult
{
    /**
     * @param array<int, string> $blockers
     * @param array<int, string> $warnings
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $blockers = [],
        public readonly array $warnings = [],
    ) {}

    public static function ok(array $warnings = []): self
    {
        return new self(true, [], $warnings);
    }

    public static function blocked(array $blockers, array $warnings = []): self
    {
        return new self(false, $blockers, $warnings);
    }

    public function hasBlockers(): bool { return $this->blockers !== []; }
    public function hasWarnings(): bool { return $this->warnings !== []; }
}
