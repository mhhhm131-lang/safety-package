<?php

namespace App\Modules\Store\Controllers;

use App\Http\Controllers\Controller;
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
 */
class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = [
            'u' => $user->username,
            'r' => $user->role,
            'n' => $user->name,
            'd' => $user->dept_code,
        ];

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

    public function show(string $key): JsonResponse
    {
        $this->assertKey($key);
        $doc = InstituteDocument::where('key', $key)->first();
        if (!$doc) {
            return response()->json(['message' => 'لا توجد وثيقة بهذا المفتاح'], 404);
        }
        return response()->json(['key' => $key, 'version' => $doc->version, 'data' => $doc->data]);
    }

    public function put(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
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

            if ($doc && $clientVersion !== $doc->version) {
                // نسخة العميل أقدم: لا نكتب فوق الأحدث، ونعيد ما عند الخادم
                return response()->json([
                    'message' => 'تغيّرت الوثيقة من جهاز آخر',
                    'version' => $doc->version,
                    'data' => $doc->data,
                ], 409);
            }

            if (!$doc) {
                $doc = new InstituteDocument(['key' => $key, 'version' => 0]);
            }
            $doc->data = $data;
            $doc->version = $doc->version + 1;
            $doc->updated_by = $userId;
            $doc->save();

            return response()->json(['key' => $key, 'version' => $doc->version]);
        });
    }

    public function destroy(string $key): JsonResponse
    {
        $this->assertKey($key);
        InstituteDocument::where('key', $key)->delete();
        return response()->json(['key' => $key, 'deleted' => true]);
    }

    private function assertKey(string $key): void
    {
        abort_unless(InstituteDocument::isAllowedKey($key), 422, 'مفتاح غير مسموح');
    }
}
