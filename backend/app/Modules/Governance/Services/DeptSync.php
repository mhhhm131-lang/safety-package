<?php

namespace App\Modules\Governance\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * الهيكل التنظيمي في الجدول هو الأصل، واللوحة تقرؤه وتكتبه بصيغة ipa-depts:
 *   [{id, name, parent, place, mgr}] — id هو code، parent هو code الأب أو ''، place هو صنف المكان HZ-xx.
 * هذا يُبقي dashboard.html بلا تعديل (BACKEND.md ٢-١) ويجعل شاشة الهيكل في الخادم والإدارة في اللوحة على مصدر واحد.
 * ٢٨-٣ (قرار ٧٨): وثيقة كل مبنى = فرعه وما تحته (وحدة الفرع رأساً)؛ مبنى بلا وحدة فرع يرى الشجرة كلها كما كان.
 * الكتابة من اللوحة لا تتعدى فرع مبناها: لا تحذف ولا تعدّل وحدة خارجه، والوحدة الجديدة بلا أب تُعلَّق تحت الفرع.
 */
class DeptSync
{
    /** وحدات المبنى: فرعه وما تحته، أو null = الشجرة كلها (مبنى بلا وحدة فرع) */
    public static function scopeIds(int $buildingId): ?array
    {
        $root = EmergencyBuilding::find($buildingId)?->branch_unit_id;
        return $root ? OrganizationUnit::descendantIdsOf($root) : null;
    }

    /** ipa-depts من الجدول — لمبنى بعينه. */
    public function toDocument(?int $buildingId = null): array
    {
        $buildingId ??= BuildingContext::id();
        $root = EmergencyBuilding::find($buildingId)?->branch_unit_id;
        $ids = self::scopeIds($buildingId);
        $units = OrganizationUnit::with('place', 'parent', 'manager')->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('order')->orderBy('id')->get();
        return $units->map(fn (OrganizationUnit $u) => [
            'id' => $u->code,
            'name' => $u->name,
            'parent' => $u->id === $root ? '' : ($u->parent?->code ?? ''),
            'place' => $u->place?->category ?? 'HZ-06',
            'mgr' => $u->manager?->name ?? ($u->manager_name ?? ''),
        ])->values()->all();
    }

    /**
     * كتابة ipa-depts القادمة من اللوحة في الجدول: إضافة وتعديل وحذف (بقواعد اللوحة: لا حذف لوحدة لها أقسام) — داخل فرع المبنى.
     * يعيد الوثيقة المعاد توليدها من الجدول.
     */
    public function fromDocument(array $rows, ?int $buildingId = null): array
    {
        $buildingId ??= BuildingContext::id();
        return DB::transaction(function () use ($rows, $buildingId) {
            $root = EmergencyBuilding::find($buildingId)?->branch_unit_id;
            $ids = self::scopeIds($buildingId);
            $byCode = OrganizationUnit::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get()->keyBy('code');
            $placeOf = function (?string $category) use ($buildingId): ?int {
                $cat = $category ?: 'HZ-06';
                return Place::where('building_id', $buildingId)->where('category', $cat)->value('id')
                    ?? Place::where('building_id', $buildingId)->where('category', 'HZ-06')->value('id')
                    ?? Place::idByCode($cat);
            };
            $seen = [];
            // ١) الإضافة والتعديل بلا آباء (حتى تُعرف كل الرموز)
            foreach ($rows as $i => $r) {
                if (!is_array($r) || empty($r['id']) || !isset($r['name'])) continue;
                $code = (string) $r['id'];
                $u = $byCode[$code] ?? null;
                // رمز قائم خارج فرع هذا المبنى: لا يُختطف ولا يُعدَّل من هنا
                if (!$u && OrganizationUnit::where('code', $code)->exists()) continue;
                $seen[$code] = true;
                $u ??= new OrganizationUnit(['code' => $code]);
                $u->name = (string) $r['name'];
                $u->place_id = $placeOf($r['place'] ?? null) ?? $u->place_id;
                $u->manager_name = ($r['mgr'] ?? '') !== '' ? (string) $r['mgr'] : null;
                $u->order = $i;
                $u->is_active = true;
                $u->save();
                $byCode[$code] = $u;
            }
            // ٢) الآباء — بلا أب = تحت وحدة الفرع (أو جذر الشجرة لمبنى بلا فرع)؛ وحدة الفرع نفسها تبقى في جذرها
            foreach ($rows as $r) {
                if (!is_array($r) || empty($r['id']) || !isset($seen[(string) $r['id']])) continue;
                $u = $byCode[(string) $r['id']];
                if ($root && $u->id === $root) continue;
                $parentCode = (string) ($r['parent'] ?? '');
                $parentId = $parentCode !== '' && isset($byCode[$parentCode]) ? $byCode[$parentCode]->id : $root;
                if ($u->parent_id !== $parentId) {
                    $u->parent_id = $parentId;
                    $u->save();
                }
            }
            // ٣) الحذف: ما لم يعد في الوثيقة (بلا أبناء وبلا موظفين وبلا أخطار تعالجها)، وإلا يُعطَّل — داخل الفرع فقط
            // خطة المعالج — الخطوة ١ (الملاحظة ٤٢): الوحدة المربوطة بخطر في العام تُعطَّل ولا تُحذف من هذا الباب أيضاً
            foreach ($byCode as $code => $u) {
                if (isset($seen[$code]) || ($root && $u->id === $root)) continue;
                if ($u->children()->exists() || $u->profiles()->exists() || $u->handledRisks()->exists()) {
                    $u->is_active = false;
                    $u->save();
                } else {
                    $u->delete();
                }
            }
            return $this->toDocument($buildingId);
        });
    }
}
