<?php

namespace App\Core\Services;

use App\Modules\Governance\Models\Setting;
use Illuminate\Support\Facades\Mail;

/**
 * صحة قناة البريد (المرحلة ٨-٢).
 *
 * **الخلل الذي تعالجه:** `NotificationService::mail()` يستدعي `Mail::raw` فعلاً، لكن بلا
 * `MAIL_MAILER` في البيئة يقع Laravel على `log`: الرسالة تُكتب في السجل ولا تصل أحداً،
 * **بلا خطأ ظاهر**. نصف قنوات §٦ معطّل صامتاً، ولا أحد يعلم إلا حين لا يصل بلاغ.
 *
 * هنا: الحالة تُقرأ من الإعداد الفعلي، وآخر نتيجة إرسال تُخزَّن وتُعرض،
 * والفشل يُسجَّل بدل أن يُبتلع.
 */
class MailHealthService
{
    public const KEY_LAST_OK    = 'mail.last_ok_at';
    public const KEY_LAST_ERROR = 'mail.last_error';
    public const KEY_LAST_TO    = 'mail.last_test_to';

    /** المرسلات التي لا تُوصل شيئاً — وجودها يعني القناة معطّلة عملياً. */
    private const DEAD_MAILERS = ['log', 'array', 'null'];

    public function status(): array
    {
        $mailer = (string) config('mail.default');
        $from   = (string) config('mail.from.address');
        $host   = (string) config('mail.mailers.smtp.host');

        $delivers = !in_array($mailer, self::DEAD_MAILERS, true);
        $ready    = $delivers && $from !== '' && ($mailer !== 'smtp' || $host !== '');

        return [
            'mailer'    => $mailer,
            'host'      => $mailer === 'smtp' ? $host : '—',
            'from'      => $from ?: '—',
            'delivers'  => $delivers,
            'ready'     => $ready,
            'reason'    => $this->reason($mailer, $delivers, $from, $host),
            'last_ok'   => Setting::get(self::KEY_LAST_OK),
            'last_to'   => Setting::get(self::KEY_LAST_TO),
            'last_error' => Setting::get(self::KEY_LAST_ERROR),
        ];
    }

    private function reason(string $mailer, bool $delivers, string $from, string $host): ?string
    {
        if (!$delivers) {
            return "المرسل «{$mailer}» لا يوصل بريداً — يكتب في السجل فقط. اضبط MAIL_MAILER وبيانات المرسل في متغيرات البيئة.";
        }
        if ($from === '') {
            return 'لا عنوان مرسِل (MAIL_FROM_ADDRESS) — أكثر الخوادم يرفض رسالة بلا مرسِل.';
        }
        if ($mailer === 'smtp' && $host === '') {
            return 'لا خادم SMTP (MAIL_HOST).';
        }

        return null;
    }

    /**
     * رسالة اختبار حقيقية. تعيد [نجحت؟، الرسالة].
     * النتيجة تُخزَّن حتى يراها من يفتح الشاشة لاحقاً.
     */
    public function sendTest(string $to, ?int $userId = null): array
    {
        $status = $this->status();
        if (!$status['delivers']) {
            Setting::set(self::KEY_LAST_ERROR, $status['reason'], $userId);

            return [false, $status['reason']];
        }

        $body = "هذه رسالة اختبار من منظومة السلامة والصحة المهنية بمعهد الإدارة العامة.\n\n"
            ."وصولها يعني أن قناة البريد تعمل، وأن الإشعارات ستصل خارج النظام.\n\n"
            .'أُرسلت في '.now()->format('Y-m-d H:i').' بتوقيت الرياض.';

        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[السلامة] رسالة اختبار'));
        } catch (\Throwable $e) {
            $message = 'تعذّر الإرسال: '.$e->getMessage();
            Setting::set(self::KEY_LAST_ERROR, $message, $userId);
            report($e);

            return [false, $message];
        }

        Setting::set(self::KEY_LAST_OK, now()->toDateTimeString(), $userId);
        Setting::set(self::KEY_LAST_TO, $to, $userId);
        Setting::set(self::KEY_LAST_ERROR, null, $userId);

        return [true, "أُرسلت رسالة الاختبار إلى {$to}. إن لم تصل خلال دقائق فافحص مجلد البريد غير المرغوب ثم بيانات المرسل."];
    }

    /** يُستدعى من `NotificationService` عند فشل إرسال إشعار — الفشل يُسجَّل لا يُبتلع. */
    public function recordFailure(\Throwable $e): void
    {
        Setting::set(self::KEY_LAST_ERROR, 'فشل إرسال إشعار في '.now()->format('Y-m-d H:i').': '.$e->getMessage());
    }
}
