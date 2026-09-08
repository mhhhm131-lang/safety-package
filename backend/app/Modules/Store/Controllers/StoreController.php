<?php

namespace App\Modules\Store\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Services\DeptSync;
use App\Modules\Incident\Services\IncidentService;
use App\Modules\Incident\Services\OccSync;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الطبقة صفر — واجهة المخزن المركزي.
 *
 * GET    /api/store?all=1[&keys=a,b]  → الجلسة + الوثائق (data نص JSON حرفي)
 * GET    /api/store?versions=1        → أرقام النسخ فقط (للاستطلاع الدوري)
 * GET    /api/store/{key}             → وثيقة واحدة
 * PUT    /api/store/{key}             → {data: نص JSON, version} — 409 إن كانت النسخة أقدم من الخادم
 * DELETE /api/store/{key}
 *
 * الجلسة: دور الواجهة (safety/tech/fm/adm/exec/cons/dept) من ملف المستخدم؛ الأدوار بلا دور واجهة
 * (موظف، مقاول، طرف خارجي) لا تصل إلى العمل اليومي → 403.
 * ipa-depts وثيقة مشتقة من جدول الهيكل التنظيمي (DeptSync) — الجدول هو الأصل.
 */
class StoreController extends Controller
{
    public const DEPTS_KEY = 'ipa-depts';

    public function __construct(private DeptSync $depts) {}

    public function index(Request $request): JsonResponse
    {
        $session = $this->session($request);
        if (!$session) {
            return $this->noDailyWork();
        }

        if ($request->boolean('versions')) {
            $versions = InstituteDocument::query()->pluck('version', 'key');
            return response()->json(['session' => $session, 'versions' => $versions]);
        }

        $q = InstituteDocument::query();
        if ($request->filled('keys')) {
            $keys = array_filter(explode(',', (string) $request->query('keys')));
            $q->whereIn('key', $keys);
        }
        $docs = [];
        foreach ($q->get() as $doc) {
            $docs[$doc->key] = ['version' => $doc->version, 'data' => $doc->data];
        }

        return response()->json(['session' => $session, 'csrf' => csrf_token(), 'docs' => $docs]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$this->session($request)) return $this->noDailyWork();
        $doc = InstituteDocument::where('key', $key)->first();
        if (!$doc) {
            return response()->json(['message' => 'لا توجد وثيقة بهذا المفتاح'], 404);
        }
        return response()->json(['key' => $key, 'version' => $doc->version, 'data' => $doc->data]);
    }

    public function put(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$this->session($request)) return $this->noDailyWork();
        $payload = $request->validate([
            'data' => 'required|string|max:16000000',
            'version' => 'nullable|integer|min:0',
        ]);
        if (!json_validate($payload['data'])) {
            return response()->json(['message' => 'المحتوى ليس JSON صحيحاً'], 422);
        }
        $clientVersion = (int) ($payload['version'] ?? 0);

        try {
            return $this->store($key, $payload['data'], $clientVersion, $request->user()->id);
        } catch (\Throwable $e) {
            // يُسجَّل كاملاً في السجل؛ ويُعاد للمستخدم المسجَّل نص مختصر ليُشخَّص الخلل بلا وصول للسجلات
            report($e);
            return response()->json([
                'message' => 'تعذّر الحفظ في الخادم',
                'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300),
            ], 500);
        }
    }

    private function store(string $key, string $data, int $clientVersion, int $userId): JsonResponse
    {
        return DB::transaction(function () use ($key, $data, $clientVersion, $userId) {
            $doc = InstituteDocument::where('key', $key)->lockForUpdate()->first();

            // ipa-occ وثيقة مشتقة تُدمج (لا تُكتب فوقها) فلا تعارض نسخ فيها
            if ($doc && $clientVersion !== $doc->version && $key !== OccSync::KEY) {
                // نسخة العميل أقدم: لا نكتب فوق الأحدث، ونعيد ما عند الخادم
                return response()->json([
                    'message' => 'تغيّرت الوثيقة من جهاز آخر',
                    'version' => $doc->version,
                    'data' => $doc->data,
                ], 409);
            }

            if ($key === self::DEPTS_KEY) {
                // الجدول هو الأصل: نكتب فيه ثم نعيد توليد الوثيقة منه
                $rows = json_decode($data, true);
                if (!is_array($rows)) {
                    return response()->json(['message' => 'صيغة الهيكل غير صحيحة'], 422);
                }
                $data = json_encode($this->depts->fromDocument($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($key === OccSync::KEY) {
                // بلاغات الشاغلين: جدول البلاغات هو الأصل؛ ما ربطه الفني في النموذج يُسجَّل ربطاً عكسياً
                $docArr = json_decode($data, true);
                if (!is_array($docArr)) {
                    return response()->json(['message' => 'صيغة بلاغات الشاغلين غير صحيحة'], 422);
                }
                $data = json_encode(app(OccSync::class)->fromDocument($docArr, $userId, app(IncidentService::class)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (!$doc) {
                $doc = new InstituteDocument(['key' => $key, 'version' => 0]);
            }
            $doc->data = $data;
            $doc->version = $doc->version + 1;
            $doc->updated_by = $userId;
            $doc->save();

            if ($key === TeamSync::KEY) {
                // ملف المكان هو الحقيقة: الفريق الأولي في وحدة الطوارئ يُشتق منه فور الحفظ (المرحلة ٤ الخطوة ٥)
                try {
                    app(TeamSync::class)->sync();
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return response()->json(['key' => $key, 'version' => $doc->version]);
        });
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$this->session($request)) return $this->noDailyWork();
        if ($key === self::DEPTS_KEY || $key === OccSync::KEY) {
            return response()->json(['message' => 'هذه الوثيقة مشتقة من الخادم ولا تُحذف من اللوحة'], 422);
        }
        InstituteDocument::where('key', $key)->delete();
        return response()->json(['key' => $key, 'deleted' => true]);
    }

    /** يُستدعى من شاشة الهيكل في الخادم بعد أي تعديل: تحديث وثيقة اللوحة ورفع نسختها. */
    public static function refreshDeptsDocument(DeptSync $sync): void
    {
        $data = json_encode($sync->toDocument(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $doc = InstituteDocument::firstOrNew(['key' => self::DEPTS_KEY]);
        $doc->data = $data;
        $doc->version = ($doc->exists ? $doc->version : 0) + 1;
        $doc->updated_by = auth()->id();
        $doc->save();
    }

    private function session(Request $request): ?array
    {
        $user = $request->user();
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return null;
        $ui = PermissionRegistry::uiRole($profile->role);
        if (!$ui) return null;
        return [
            'u' => $user->username,
            'r' => $ui,
            'role' => $profile->role,
            'n' => $user->name,
            'd' => $profile->organizationUnit?->code,
            'p' => $profile->place?->code,
        ];
    }

    private function noDailyWork(): JsonResponse
    {
        return response()->json(['message' => 'حسابك لا يملك صلاحية العمل اليومي (اللوحة ونماذج الفحص). الوثائق مفتوحة للجميع.'], 403);
    }

    private function assertKey(string $key): void
    {
        abort_unless(InstituteDocument::isAllowedKey($key), 422, 'مفتاح غير مسموح');
    }
}
