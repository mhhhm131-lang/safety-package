<?php

namespace App\Console\Commands;

use App\Core\Services\BackupService;
use Illuminate\Console\Command;

/**
 * نسخة احتياطية يومية (المرحلة ٨-٣).
 *
 * على Render المجاني لا قرص دائم، فما يُكتب هنا يزول عند إعادة النشر. النسخة التي
 * **تبقى** هي التي يُنزّلها المستخدم من شاشة الإغلاق. هذا الأمر شبكة أمان قصيرة الأجل،
 * ويصير نسخاً حقيقياً على أي بيئة بقرص.
 */
class IpaBackup extends Command
{
    protected $signature = 'ipa:backup {--keep=7 : كم نسخة تبقى}';

    protected $description = 'كتابة نسخة احتياطية من القاعدة وحذف ما زاد عن العدد المحفوظ.';

    public function handle(BackupService $backup): int
    {
        $result = $backup->writeToDisk();
        $this->info(sprintf(
            'نسخة: %s — %d جدولاً، %d صفاً، %s.',
            basename($result['path']),
            $result['tables'],
            $result['rows'],
            $this->size($result['bytes']),
        ));

        $keep = max(1, (int) $this->option('keep'));
        $pruned = $backup->prune($keep);
        if ($pruned) {
            $this->line('حُذف الأقدم: '.implode('، ', $pruned));
        }
        $this->line('المحفوظ الآن: '.count($backup->existing())." من {$keep}.");

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' ميغابايت'
            : round($bytes / 1024).' كيلوبايت';
    }
}
