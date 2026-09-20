<?php

namespace App\Console\Commands;

use App\Core\Trial\TrialFill;
use App\Core\Trial\TrialMode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): يشغّل «وضع التجربة» ويعبّئ النظام كله.
 *
 *   php artisan ipa:trial-start                 كلمة مرور قوية تُولَّد وتُطبع مرة واحدة
 *   php artisan ipa:trial-start --password=...  للتطوير المحلي وأدوات المتصفح
 *   php artisan ipa:trial-start --no-fill       الوضع وحده بلا تعبئة
 */
class IpaTrialStart extends Command
{
    protected $signature = 'ipa:trial-start {--password= : كلمة مرور الحسابات التجريبية (وإلا تُولَّد)} {--no-fill : شغّل الوضع بلا تعبئة}';

    protected $description = 'يشغّل وضع التجربة ويعبّئ النظام: كل ما يُنشأ بعده يُحذف بأمر ipa:trial-stop.';

    public function handle(TrialMode $mode, TrialFill $fill): int
    {
        try {
            $r = $mode->start();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info("وضع التجربة مشغَّل — خط أساس {$r['tables']} جدولاً ونسخة ملفات المعهد والإعدادات محفوظة.");
        if ($this->option('no-fill')) return self::SUCCESS;

        $given = (string) $this->option('password');
        $password = $given !== '' ? $given : Str::password(14, symbols: false);
        foreach ($fill->run($password) as $label => $n) $this->line(sprintf('  %-20s %d', $label, $n));
        $this->line('الحسابات التجريبية تبدأ بـ '.TrialFill::PREFIX.' (مثل tj.hr.m مدير، tj.hr.c منسق سلامة، tj.hr.e1 موظف).');
        if ($given === '') $this->warn("كلمة مرورها (تظهر مرة واحدة ولا تُحفظ): $password");
        return self::SUCCESS;
    }
}
