<?php

namespace App\Modules\Store\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Services\BuildingContext;
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
 * GET    /api/store?all=1[&keys=a,b][&b=id]  → الجلسة + وثائق مبنى الجلسة (data نص JSON حرفي)؛ `b` يبدّل المبنى لمن يحق له
 * GET    /api/store?versions=1               → أرقام النسخ فقط (للاستطلاع الدوري)
 * GET    /api/store/{key}                    → وثيقة واحدة
 * PUT    /api/store/{key}                    → {data: نص JSON, version[, b]} — 409 إن كانت النسخة أقدم من الخادم؛ 422 إن كان `b` غير مبنى الجلسة
 * DELETE /api/store/{key}[?b=]
 *
 * الجلسة: دور الواجهة (safety/tech/fm/adm/exec/cons/dept) من ملف المستخدم؛ الأدوار بلا دور واجهة
 * (موظف، مقاول، طرف خارجي) لا تصل إلى العمل اليومي → 403.
 * ipa-depts وثيقة مشتقة من جدول الهيكل التنظيمي (DeptSync) — الجدول هو الأصل.
 * ٢٨-٣ (قرار ٧٨): كل وثيقة بمبناها؛ الصفحة نفسها تعمل لكل مبنى والخادم يقدّم وثائق مبنى الجلسة (BuildingContext).
 */
class StoreController extends Controller
{
    public const DEPTS_KEY = 'ipa-depts';

    /**
     * ١٩-٧ (قرار ٤٨): وثيقتان لا تُكتبان من المتصفح بعد إخفاء اللوحة — ملف الفرق والخطتين يكتبه PlaceProfile بصلاحياته في الخادم،
     * والهيكل تكتبه شاشة الهيكل التنظيمي. القراءة باقية. 422 كالمفتاح غير المسموح، فيُسقطه طابور ipa-store.js ولا يعلق.
     */
    public const SERVER_ONLY = ['ipa-place', self::DEPTS_KEY];

    public function __construct(private DeptSync $depts) {}

    public function index(Request $request): JsonResponse
    {
        $session = $this->session($request);
        if (!$session) {
            return $this->noDailyWork();
        }
        // ٢٨-٣: رابط يحمل مبنى (إشعار عن نموذج مبنى آخر) يبدّل مبنى الجلسة لمن يحق له، ويُهمل لغيره
        if ($request->filled('b')) {
            BuildingContext::set($request->user(), (int) $request->query('b'));
            $session = $this->session($request);
        }
        $b = (int) $session['b'];

        if ($request->boolean('versions')) {
            $versions = InstituteDocument::query()->ofBuilding($b)->pluck('version', 'key');
            return response()->json(['session' => $session, 'versions' => $versions]);
        }

        $q = InstituteDocument::query()->ofBuilding($b);
        if ($request->filled('keys')) {
            $keys = array_filter(explode(',', (string) $request->query('keys')));
            $q->whereIn('key', $keys);
        }
        // ١٣-٧-٢ (قرار ٤١ مشكلة ٣): صور المخالفات (ipa-photo-*) ثقيلة (مئات الكيلوبايتات للواحدة) ولا يحتاجها إلا من يفتح بلاغها —
        // فلا تُرسل مع الفتح الأولي ولا تُخزَّن في كل جهاز؛ تُعاد بنسختها فقط (lazy) وتُجلب بـ show() عند الحاجة. `?photos=1` يعيدها كاملة.
        $withPhotos = $request->boolean('photos');
        $docs = [];
        foreach ($q->get() as $doc) {
            $lazy = !$withPhotos && str_starts_with($doc->key, 'ipa-photo-');
            $docs[$doc->key] = $lazy
                ? ['version' => $doc->version, 'data' => null, 'lazy' => true]
                : ['version' => $doc->version, 'data' => $doc->data];
        }

        return response()->json(['session' => $session, 'csrf' => csrf_token(), 'docs' => $docs]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$session = $this->session($request)) return $this->noDailyWork();
        $doc = InstituteDocument::doc($key, (int) $session['b']);
        if (!$doc) {
            return response()->json(['message' => 'لا توجد وثيقة بهذا المفتاح'], 404);
        }
        return response()->json(['key' => $key, 'version' => $doc->version, 'data' => $doc->data]);
    }

    public function put(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$session = $this->session($request)) return $this->noDailyWork();
        if (in_array($key, self::SERVER_ONLY, true)) return $this->serverOnly();
        $payload = $request->validate([
            'data' => 'required|string|max:16000000',
            'version' => 'nullable|integer|min:0',
            'b' => 'nullable|integer',
        ]);
        if (!json_validate($payload['data'])) {
            return response()->json(['message' => 'المحتوى ليس JSON صحيحاً'], 422);
        }
        $b = (int) $session['b'];
        if ($stale = $this->staleBuilding($payload['b'] ?? null, $b)) return $stale;
        $clientVersion = (int) ($payload['version'] ?? 0);

        try {
            return $this->store($key, $payload['data'], $clientVersion, $request->user()->id, $b);
        } catch (\Throwable $e) {
            // يُسجَّل كاملاً في السجل؛ ويُعاد للمستخدم المسجَّل نص مختصر ليُشخَّص الخلل بلا وصول للسجلات
            report($e);
            return response()->json([
                'message' => 'تعذّر الحفظ في الخادم',
                'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300),
            ], 500);
        }
    }

    private function store(string $key, string $data, int $clientVersion, int $userId, int $b): JsonResponse
    {
        $oldData = null;
        $response = DB::transaction(function () use ($key, $data, $clientVersion, $userId, $b, &$oldData) {
            $doc = InstituteDocument::where('key', $key)->where('building_id', $b)->lockForUpdate()->first();
            $oldData = $doc?->data;

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
                // الجدول هو الأصل: نكتب فيه ثم نعيد توليد الوثيقة منه — داخل فرع مبنى الجلسة (٢٨-٣)
                $rows = json_decode($data, true);
                if (!is_array($rows)) {
                    return response()->json(['message' => 'صيغة الهيكل غير صحيحة'], 422);
                }
                $data = json_encode($this->depts->fromDocument($rows, $b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($key === OccSync::KEY) {
                // بلاغات الشاغلين: جدول البلاغات هو الأصل؛ ما ربطه الفني في النموذج يُسجَّل ربطاً عكسياً
                $docArr = json_decode($data, true);
                if (!is_array($docArr)) {
                    return response()->json(['message' => 'صيغة بلاغات الشاغلين غير صحيحة'], 422);
                }
                $data = json_encode(app(OccSync::class)->fromDocument($docArr, $userId, app(IncidentService::class), $b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (!$doc) {
                $doc = new InstituteDocument(['key' => $key, 'version' => 0, 'building_id' => $b]);
            }
            $doc->data = $data;
            $doc->version = $doc->version + 1;
            $doc->updated_by = $userId;
            $doc->save();

            if ($key === TeamSync::KEY) {
                // ملف المكان هو الحقيقة: الفريق الأولي في وحدة الطوارئ يُشتق منه فور الحفظ (المرحلة ٤ الخطوة ٥)
                try {
                    app(TeamSync::class)->sync($b);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return response()->json(['key' => $key, 'version' => $doc->version]);
        });

        // المرحلة ١٤: إشعارات الفحص وسجل السلامة بعد إتمام الحفظ وخارج معاملته — تعثّرها لا يُسقط حفظ عمل الفني
        if ($response->getStatusCode() === 200 && \App\Modules\Store\Services\InspectionWatch::form($key)) {
            try {
                app(\App\Modules\Store\Services\InspectionWatch::class)->afterSave($key, $oldData, $data, $b);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return $response;
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        if (!$session = $this->session($request)) return $this->noDailyWork();
        if (in_array($key, self::SERVER_ONLY, true)) return $this->serverOnly();
        if ($key === self::DEPTS_KEY || $key === OccSync::KEY) {
            return response()->json(['message' => 'هذه الوثيقة مشتقة من الخادم ولا تُحذف من اللوحة'], 422);
        }
        $b = (int) $session['b'];
        $sent = $request->input('b', $request->query('b'));
        if ($stale = $this->staleBuilding($sent, $b)) return $stale;
        InstituteDocument::where('key', $key)->where('building_id', $b)->delete();
        return response()->json(['key' => $key, 'deleted' => true]);
    }

    /** ٢٨-٣: المتصفح يرسل مبنى صفحته؛ إن خالف مبنى الجلسة (بُدّل من تبويب آخر) يُرفض الحفظ ويُطلب إعادة التحميل */
    private function staleBuilding($sent, int $b): ?JsonResponse
    {
        if ($sent === null || $sent === '' || (int) $sent === $b) return null;
        return response()->json(['message' => 'هذه الصفحة مفتوحة على مبنى غير مبنى جلستك الحالي — أعد تحميلها ثم أعد ما كتبت', 'building' => $b], 422);
    }

    /** يُستدعى من شاشة الهيكل في الخادم بعد أي تعديل: تحديث وثيقة اللوحة لكل مبنى ورفع نسختها. */
    public static function refreshDeptsDocument(DeptSync $sync): void
    {
        foreach (EmergencyBuilding::query()->pluck('id') as $b) {
            $data = json_encode($sync->toDocument((int) $b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $doc = InstituteDocument::firstOrNew(['key' => self::DEPTS_KEY, 'building_id' => (int) $b]);
            $doc->data = $data;
            $doc->version = ($doc->exists ? $doc->version : 0) + 1;
            $doc->updated_by = auth()->id();
            $doc->save();
        }
    }

    private function session(Request $request): ?array
    {
        $user = $request->user();
        $profile = $user->profile;
        if (!$profile || !$profile->is_active) return null;
        $ui = PermissionRegistry::uiRole($profile->role);
        if (!$ui) return null;
        $building = BuildingContext::current($user);
        return [
            'u' => $user->username,
            'r' => $ui,
            'role' => $profile->role,
            'n' => $user->name,
            'd' => $profile->organizationUnit?->code,
            // ٢٨-٣: صفحات المعهد تعرف الأصناف (HZ-xx) داخل مبنى الجلسة — لا الرمز الكامل
            'p' => $profile->place?->category,
            'b' => $building->id,
            'bn' => $building->name,
        ];
    }

    private function serverOnly(): JsonResponse
    {
        return response()->json(['message' => 'هذه الوثيقة تُحرَّر من شاشات المنظومة (ملف المكان، الهيكل التنظيمي) لا من المتصفح مباشرة'], 422);
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
