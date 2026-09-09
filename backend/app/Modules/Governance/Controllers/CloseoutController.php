<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Services\BackupService;
use App\Core\Services\CloseoutService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
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

    public function __construct(
        private readonly CloseoutService $closeout,
        private readonly BackupService $backup,
    ) {}

    public function index()
    {
        return view('modules.governance.closeout', [
            'inventory'     => $this->closeout->inventory(),
            'preserved'     => $this->closeout->preserved(),
            'unclassified'  => $this->closeout->unclassifiedTables(),
            'demoAccounts'  => $this->demoAccounts(),
            'realAdmin'     => $this->realAdmin(),
            'confirmWord'   => self::CONFIRM_WORD,
            'backups'       => $this->backup->existing(),
        ]);
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

    /** تعطيل الحسابات التجريبية — بحارسَين. */
    public function disableDemo(Request $request)
    {
        // الحارس الثاني: من يعطّل وهو داخل بحساب تجريبي يقفل الباب على نفسه في منتصف الإجراء
        // (يظهر 403 بلا رسالة). الخطة تقول: أنشئ حسابك الحقيقي وتحقّق من دخوله **ثم** عطّل.
        if (in_array($request->user()?->username, CloseoutService::DEMO_USERNAMES, true)) {
            return back()->with('error',
                'أنت داخل بحساب تجريبي ('.$request->user()->username.'). '
                .'ادخل بحسابك الحقيقي أولاً ثم عطّلها — وإلا أقفلت الباب على نفسك في منتصف الإجراء.');
        }

        if (!$this->realAdmin()) {
            return back()->with('error',
                'لا يوجد حساب «مسؤول السلامة» نشط خارج الحسابات التجريبية. '
                .'أنشئه من شاشة المستخدمين وتحقّق من دخوله أولاً — التعطيل الآن يغلق الباب على الجميع.');
        }

        $code = Artisan::call('ipa:demo-off');
        $out = trim(Artisan::output());

        return $code === 0
            ? back()->with('success', $out ?: 'تم التعطيل.')
            : back()->with('error', $out ?: 'تعذّر التعطيل.');
    }

    /** @return \Illuminate\Support\Collection<int, array{username: string, name: string, role: string, active: bool}> */
    private function demoAccounts()
    {
        return User::whereIn('username', CloseoutService::DEMO_USERNAMES)
            ->orderBy('username')
            ->get(['id', 'username', 'name'])
            ->map(function (User $u) {
                $profile = UserProfile::where('user_id', $u->id)->first();

                return [
                    'username' => $u->username,
                    'name'     => $u->name,
                    'role'     => $profile?->role ?? '—',
                    'active'   => (bool) $profile?->is_active,
                ];
            });
    }

    private function realAdmin(): ?UserProfile
    {
        return UserProfile::where('role', 'system_admin')
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->whereNotIn('username', CloseoutService::DEMO_USERNAMES))
            ->with('user:id,username,name')
            ->first();
    }
}
