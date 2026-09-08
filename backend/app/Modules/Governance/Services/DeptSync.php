<?php

namespace App\Modules\Governance\Services;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * الهيكل التنظيمي في الجدول هو الأصل، واللوحة تقرؤه وتكتبه بصيغة ipa-depts:
 *   [{id, name, parent, place, mgr}] — id هو code، parent هو code الأب أو ''، place هو HZ-xx.
 * هذا يُبقي dashboard.html بلا تعديل (BACKEND.md ٢-١) ويجعل شاشة الهيكل في الخادم والإدارة في اللوحة على مصدر واحد.
 */
class DeptSync
{
    /** ipa-depts من الجدول. */
    public function toDocument(): array
    {
        $units = OrganizationUnit::with('place', 'parent', 'manager')->where('is_active', true)->orderBy('order')->orderBy('id')->get();
        return $units->map(fn (OrganizationUnit $u) => [
            'id' => $u->code,
            'name' => $u->name,
            'parent' => $u->parent?->code ?? '',
            'place' => $u->place?->code ?? 'HZ-06',
            'mgr' => $u->manager?->name ?? ($u->manager_name ?? ''),
        ])->values()->all();
    }

    /**
     * كتابة ipa-depts القادمة من اللوحة في الجدول: إضافة وتعديل وحذف (بقواعد اللوحة: لا حذف لوحدة لها أقسام).
     * يعيد الوثيقة المعاد توليدها من الجدول.
     */
    public function fromDocument(array $rows): array
    {
        return DB::transaction(function () use ($rows) {
            $byCode = OrganizationUnit::all()->keyBy('code');
            $seen = [];
            // ١) الإضافة والتعديل بلا آباء (حتى تُعرف كل الرموز)
            foreach ($rows as $i => $r) {
                if (!is_array($r) || empty($r['id']) || !isset($r['name'])) continue;
                $code = (string) $r['id'];
                $seen[$code] = true;
                $u = $byCode[$code] ?? new OrganizationUnit(['code' => $code]);
                $u->name = (string) $r['name'];
                $u->place_id = Place::idByCode($r['place'] ?? null) ?? $u->place_id ?? Place::idByCode('HZ-06');
                $u->manager_name = ($r['mgr'] ?? '') !== '' ? (string) $r['mgr'] : null;
                $u->order = $i;
                $u->is_active = true;
                $u->save();
                $byCode[$code] = $u;
            }
            // ٢) الآباء
            foreach ($rows as $r) {
                if (!is_array($r) || empty($r['id'])) continue;
                $u = $byCode[(string) $r['id']];
                $parentCode = (string) ($r['parent'] ?? '');
                $parentId = $parentCode !== '' && isset($byCode[$parentCode]) ? $byCode[$parentCode]->id : null;
                if ($u->parent_id !== $parentId) {
                    $u->parent_id = $parentId;
                    $u->save();
                }
            }
            // ٣) الحذف: ما لم يعد في الوثيقة (بلا أبناء وبلا موظفين)، وإلا يُعطَّل
            foreach ($byCode as $code => $u) {
                if (isset($seen[$code])) continue;
                if ($u->children()->exists() || $u->profiles()->exists()) {
                    $u->is_active = false;
                    $u->save();
                } else {
                    $u->delete();
                }
            }
            return $this->toDocument();
        });
    }
}
