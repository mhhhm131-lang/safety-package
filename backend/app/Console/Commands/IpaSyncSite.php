<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * ينسخ ملفات المعهد (HTML كما هي) من جذر المستودع إلى public/ لتُقدَّم من الخادم نفسه.
 * لا يعدّل الأصل. يُشغَّل محلياً بعد أي تغيير في الملفات، وفي بناء الحاوية.
 */
class IpaSyncSite extends Command
{
    protected $signature = 'ipa:sync-site {--from= : مجلد المصدر (افتراضياً المجلد الأعلى)}';

    protected $description = 'نسخ صفحات المعهد إلى public/ (الوثائق، النماذج العشرة، اللوحة، البطاقات، القصة)';

    /** ما يُنسخ من الجذر: المجلدات والملفات المفردة. */
    public const DIRS = ['HZ-00-safety-center', 'HZ-01-basement', 'HZ-02-electrical', 'HZ-03-hvac', 'HZ-04-datacenter',
        'HZ-05-restaurants', 'HZ-06-offices', 'HZ-07-halls', 'HZ-08-storage', 'role-cards', 'story', 'archive'];

    public function handle(Filesystem $fs): int
    {
        $from = rtrim($this->option('from') ?: dirname(base_path()), '/\\');
        $to = public_path();
        $count = 0;

        foreach (self::DIRS as $dir) {
            $src = $from.DIRECTORY_SEPARATOR.$dir;
            if (!$fs->isDirectory($src)) {
                $this->warn("غير موجود: {$dir}");
                continue;
            }
            $dst = $to.DIRECTORY_SEPARATOR.$dir;
            $fs->deleteDirectory($dst);
            $fs->copyDirectory($src, $dst);
            $count += count($fs->allFiles($dst));
        }

        foreach ($fs->glob($from.DIRECTORY_SEPARATOR.'*.html') as $file) {
            $fs->copy($file, $to.DIRECTORY_SEPARATOR.basename($file));
            $count++;
        }
        foreach (['support.js'] as $single) {
            if ($fs->exists($from.DIRECTORY_SEPARATOR.$single)) {
                $fs->copy($from.DIRECTORY_SEPARATOR.$single, $to.DIRECTORY_SEPARATOR.$single);
                $count++;
            }
        }

        $this->info("نُسخ {$count} ملفاً من {$from} إلى public/");
        return self::SUCCESS;
    }
}
