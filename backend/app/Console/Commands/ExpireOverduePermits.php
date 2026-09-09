<?php

namespace App\Console\Commands;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Project\Services\ProjectContractorService;
use Illuminate\Console\Command;

/**
 * مسح يومي: التصاريح المعتمدة أو النشطة التي مضى تاريخ انتهائها تُنقل إلى «منتهي الصلاحية»،
 * وتأهيل المقاولين المنتهي كذلك. يمنع بقاء تصريح «نشط» في القاعدة وقد انتهى فعلاً.
 * يُجدول في routes/console.php.
 */
class ExpireOverduePermits extends Command
{
    protected $signature = 'permits:expire-overdue {--dry-run : عرض ما سينتهي بلا تغيير}';

    protected $description = 'إنهاء صلاحية التصاريح وتأهيلات المقاولين التي مضى تاريخها.';

    public function handle(PermitService $permits, ProjectContractorService $contractors): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = now();

        $overdue = Permit::query()
            ->whereIn('status', [Permit::STATUS_APPROVED, Permit::STATUS_SAFETY_APPROVED, Permit::STATUS_ACTIVE])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->get();

        $permitCount = 0;
        foreach ($overdue as $permit) {
            $this->line("التصريح {$permit->code} ({$permit->status}، انتهى {$permit->expires_at}) ← منتهي");
            if ($dry) {
                continue;
            }
            try {
                // معتمد/معتمد من السلامة لا ينتقل إلى «منتهي» في آلة الحالة: يُلغى بسبب مسجَّل.
                $target = $permit->status === Permit::STATUS_ACTIVE ? Permit::STATUS_EXPIRED : Permit::STATUS_CANCELLED;
                $permits->transition($permit, $target, null, 'انتهت المدة قبل التفعيل — أُنهي آلياً.');
                $permitCount++;
            } catch (TransitionException $e) {
                $this->warn('  تُخطّي: '.$e->getMessage());
            }
        }

        $overdueContractors = ProjectContractor::query()
            ->whereNotIn('qualification_status', ['expired', 'suspended'])
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $now->toDateString())
            ->get();

        $contractorCount = 0;
        foreach ($overdueContractors as $pc) {
            $this->line("تأهيل المقاول #{$pc->id} ({$pc->qualification_status}، انتهى {$pc->expires_at}) ← منتهٍ");
            if ($dry) {
                continue;
            }
            try {
                $contractors->transition($pc, 'expired', null, 'انتهت مدة التأهيل — أُنهي آلياً.');
                $contractorCount++;
            } catch (\Throwable $e) {
                $this->warn('  تُخطّي: '.$e->getMessage());
            }
        }

        $this->info(sprintf('%s: %d تصريحاً و%d تأهيل مقاول.',
            $dry ? 'عرض فقط' : 'تم', $permitCount, $contractorCount));

        return self::SUCCESS;
    }
}
