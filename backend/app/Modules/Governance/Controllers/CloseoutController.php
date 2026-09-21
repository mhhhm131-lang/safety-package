<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Services\BackupService;
use App\Core\Services\CloseoutService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * شاشة الإغلاق (المرحلة ٨-١): تعطيل الحسابات التجريبية وحذف بيانات التجربة.
 *
 * **لماذا شاشة لا سطر أوامر:** Render المجاني بلا Shell، والتسليم لا ينتظر مطوّراً.
 * الشاشة تعرض الجرد أولاً — ماذا سيُحذف وكم، وماذا سيبقى — والحذف يحتاج كتابة كلمة تأكيد.
 */
class CloseoutController extends Controller
{
    /** الكلمة التي يكتبها المستخدم ليؤكد الحذف — لا زر واحد يمحو قاعدة. */
    public const CONFIRM_WORD = 'احذف';
    public const CONFIRM_BOOK = 'استبدل';

    public function __construct(
        private readonly CloseoutService $closeout,
        private readonly BackupService $backup,
    ) {}

    public function index()
    {
        // مرور واحد على الحسابات: bcrypt بطيء متعمَّداً، وفحصه مرتين أوقع الصفحة في انقطاع
        // الاتصال على المنشور. يُعرض ما كان تجريبياً وما بقي خطراً مهما كان اسمه.
        $audit = $this->closeout->accountAudit();
        $accounts = $audit->filter(fn (array $a) => $a['seeded']
            || in_array($a['username'], CloseoutService::DEMO_USERNAMES, true))->values();

        return view('modules.governance.closeout', [
            'inventory'     => $this->closeout->inventory(),
            'preserved'     => $this->closeout->preserved(),
            'unclassified'  => $this->closeout->unclassifiedTables(),
            'demoAccounts'  => $accounts,
            'risky'         => $audit->where('seeded', true)->count(),
            'confirmWord'   => self::CONFIRM_WORD,
            'confirmBook'   => self::CONFIRM_BOOK,
            'book'          => $this->closeout->bookStatus(),
            'bookBlockers'  => $this->closeout->bookReplaceBlockers(),
            'backups'       => $this->backup->existing(),
            // ٢١-٩ (قرار ٥٤): وضع التجربة
            'trialOn'        => \App\Core\Trial\TrialMode::isOn(),
            'trialInventory' => \App\Core\Trial\TrialMode::isOn() ? app(\App\Core\Trial\TrialMode::class)->inventory() : [],
            'trialFilling'   => \App\Core\Trial\TrialFill::pending(),
            'confirmTrial'   => self::CONFIRM_TRIAL,
        ]);
    }

    public const CONFIRM_TRIAL = 'أنهِ التجربة';

    /** ٢١-٩: يشغّل وضع التجربة ويبدأ تعبئة تُستأنف؛ كلمة مرور الحسابات التجريبية تُولَّد قوية وتُعاد مرة واحدة ولا تُحفظ. */
    public function trialStart(Request $request, \App\Core\Trial\TrialMode $mode, \App\Core\Trial\TrialFill $fill): \Illuminate\Http\JsonResponse
    {
        try {
            $mode->start($request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $password = \Illuminate\Support\Str::password(14, symbols: false);
        $fill->begin($password, $request->user()->id);
        app('audit.logger')->log($request, 'trial_start', 'Setting', null, 'تشغيل وضع التجربة');
        return response()->json(['password' => $password, 'prefix' => \App\Core\Trial\TrialFill::PREFIX]);
    }

    /** خطوة من التعبئة بميزانية زمنية دون حد قطع الخادم؛ تستدعيها الشاشة حتى `done`. */
    public function trialFill(\App\Core\Trial\TrialFill $fill): \Illuminate\Http\JsonResponse
    {
        return response()->json($fill->next(20));
    }

    public function trialStop(Request $request, \App\Core\Trial\TrialMode $mode)
    {
        $request->validate(['confirm' => ['required', 'in:'.self::CONFIRM_TRIAL]], ['confirm.in' => 'اكتب «'.self::CONFIRM_TRIAL.'» للتأكيد.', 'confirm.required' => 'اكتب «'.self::CONFIRM_TRIAL.'» للتأكيد.']);
        try {
            $r = $mode->stop();
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $left = $r['left'] ? ' — بقي ما لم يُحذف: '.implode('، ', array_map(fn ($t, $n) => "$t ($n)", array_keys($r['left']), $r['left'])) : '';
        return back()->with($r['left'] ? 'err' : 'ok', sprintf('انتهت التجربة: حُذف %d صفاً من %d جدولاً، وأُعيدت ملفات المعهد والإعدادات كما كانت.', array_sum($r['deleted']), count($r['deleted'])).$left);
    }

    /**
     * تنزيل نسخة احتياطية الآن.
     *
     * **لماذا تنزيلاً لا ملفاً على الخادم:** الخطة المجانية بلا قرص دائم، فما يُكتب في
     * الحاوية يزول عند إعادة النشر. النسخة التي تبقى هي التي يحفظها المستخدم عنده.
     */
    public function backupDownload(): StreamedResponse
    {
        $name = $this->backup->filename();

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            $this->backup->dump(function (string $line) use ($out) {
                fwrite($out, $line);
            });
            fclose($out);
        }, $name, ['Content-Type' => 'application/sql; charset=UTF-8']);
    }

    /** حذف العمل التشغيلي. المرجعي لا يُمس. */
    public function purge(Request $request)
    {
        $request->validate(
            ['confirm' => ['required', 'string', 'in:'.self::CONFIRM_WORD]],
            ['confirm.in' => 'اكتب كلمة «'.self::CONFIRM_WORD.'» للتأكيد.'],
            ['confirm' => 'كلمة التأكيد'],
        );

        if ($unclassified = $this->closeout->unclassifiedTables()) {
            return back()->with('error', 'جداول بلا تصنيف: '.implode('، ', $unclassified).'. صنّفها قبل الحذف.');
        }

        $deleted = $this->closeout->purge();

        return back()->with('success', sprintf(
            'حُذف %d صفاً من %d جدولاً. المرجعي كما هو.',
            array_sum($deleted),
            count($deleted),
        ));
    }

    /** استبدال كتاب المعهد (المرحلة ٩): يُرفض ما دام هناك عمل تشغيلي مربوط بالمخاطر. */
    public function replaceBook(Request $request)
    {
        $request->validate(
            ['confirm' => ['required', 'string', 'in:'.self::CONFIRM_BOOK]],
            ['confirm.in' => 'اكتب كلمة «'.self::CONFIRM_BOOK.'» للتأكيد.'],
            ['confirm' => 'كلمة التأكيد'],
        );
        try {
            $status = $this->closeout->replaceBook();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', sprintf('استُبدل الكتاب: %d أصناف، %d فرعاً، %d خطراً في السجل العام.',
            $status['الأصناف الرئيسية'], $status['الفروع'], $status['مخاطر السجل العام']));
    }

    /** تعطيل الحسابات التجريبية — بحارسَين. */
    public function disableDemo(Request $request)
    {
        // الحارس الثاني: من يعطّل وكلمته هي المبذورة يقفل الباب على نفسه في منتصف الإجراء
        // (تظهر صفحة ممنوعة بلا رسالة). الاسم لا يهم — التعطيل يشمل كل كلمة مبذورة.
        $actor = $request->user();
        if ($actor && $this->closeout->stillSeeded($actor)) {
            return back()->with('error',
                'حسابك ('.$actor->username.') ما زال على كلمة المرور المبذورة، فسيشمله التعطيل. '
                .'غيّر كلمتك أو ادخل بحساب آخر أولاً — وإلا أقفلت الباب على نفسك في منتصف الإجراء.');
        }

        $code = Artisan::call('ipa:demo-off');
        $out = trim(Artisan::output());

        return $code === 0
            ? back()->with('success', $out ?: 'تم التعطيل.')
            : back()->with('error', $out ?: 'تعذّر التعطيل.');
    }

}
