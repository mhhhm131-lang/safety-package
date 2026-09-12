<?php

namespace App\Core\Inbox;

use Carbon\CarbonInterface;

/**
 * المرحلة ١١-٢ (قرار ٣٤): «المهمة» — سؤال واحد لشخص واحد بزر واحد (أو زرين).
 * كائن قراءة لا جدول: تُشتق حيّاً من حالة مصدرها وتختفي حين تتغير.
 */
final class Task
{
    /**
     * @param string $key مفتاح منع التكرار، مثل incident:12:field
     * @param array{label:string,url:string,method?:string} $primary الزر الأساسي
     * @param array{label:string,url:string,method?:string}|null $secondary زر ثانٍ اختياري
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $question,
        public readonly array $primary,
        public readonly ?array $secondary = null,
        public readonly ?CarbonInterface $dueAt = null,
        public readonly bool $isOverdue = false,
        public readonly ?string $place = null,
        public readonly ?string $detailsUrl = null,
        public readonly ?CarbonInterface $createdAt = null,
    ) {}

    public function primaryMethod(): string
    {
        return strtoupper($this->primary['method'] ?? 'GET');
    }

    public function secondaryMethod(): string
    {
        return strtoupper($this->secondary['method'] ?? 'GET');
    }
}
