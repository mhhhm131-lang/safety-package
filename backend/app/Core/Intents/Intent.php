<?php

namespace App\Core\Intents;

/**
 * المرحلة ١٢ (قرار ٣٥): «النية» — ما يريد المستخدم فعله بلغته، والشاشة التي تفتحه بالمكان والدور محددين.
 */
final class Intent
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon = 'bi-arrow-left-circle',
        public readonly ?string $hint = null,
        public readonly bool $primary = false,
        public readonly string $group = 'عام',
    ) {}
}
