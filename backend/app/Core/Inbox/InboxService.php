<?php

namespace App\Core\Inbox;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * المرحلة ١١-٢ (قرار ٣٤): «ما ينتظرك الآن» — مُجمِّع حي فوق الوحدات، بلا جدول وبلا إشعار ثانٍ.
 * الترتيب: المتأخر أولاً، ثم الأقرب مهلةً، ثم الأقدم. لا أولوية مخترعة.
 */
class InboxService
{
    /** المصادر بترتيب ثابت؛ كل مصدر يعيد استخدام استعلام وحدته القائم (BACKEND.md ٧-٢ «المرحلة ١١»). */
    public const SOURCES = [
        \App\Modules\Incident\Inbox\IncidentTasks::class,
        \App\Modules\Form\Inbox\FormTasks::class,
        \App\Modules\Risk\Inbox\RiskTasks::class,
        \App\Modules\Permit\Inbox\PermitTasks::class,
    ];

    /** @return Collection<int, Task> */
    public function forUser(User $user): Collection
    {
        $tasks = collect();
        foreach (self::SOURCES as $class) {
            /** @var TaskSource $source */
            $source = app($class);
            $tasks = $tasks->merge($source->tasksFor($user));
        }
        return $tasks->unique(fn (Task $t) => $t->key)
            ->sort(function (Task $a, Task $b) {
                if ($a->isOverdue !== $b->isOverdue) return $a->isOverdue ? -1 : 1;
                if ($a->dueAt && $b->dueAt && !$a->dueAt->eq($b->dueAt)) return $a->dueAt->lt($b->dueAt) ? -1 : 1;
                if ((bool) $a->dueAt !== (bool) $b->dueAt) return $a->dueAt ? -1 : 1;
                $ca = $a->createdAt?->getTimestamp() ?? 0; $cb = $b->createdAt?->getTimestamp() ?? 0;
                return $ca <=> $cb;
            })->values();
    }

    public function countFor(User $user): int
    {
        return $this->forUser($user)->count();
    }
}
