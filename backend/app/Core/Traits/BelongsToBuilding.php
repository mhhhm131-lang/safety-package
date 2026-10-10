<?php

namespace App\Core\Traits;

use App\Modules\Governance\Services\BuildingScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * قرار ٨٤: النموذج الذي له مبنى يُقرأ داخل مباني الحساب تلقائياً — على مثال `BelongsToTenant` في OHSMS والمبنى هو المستأجر.
 *
 *   - نطاق عام «building» على كل استعلام: `whereIn(building_id, مباني الحساب)`؛ بلا مستخدم أو لمن يرى الكل بلا قيد.
 *   - حارس ربط المعرّف في الرابط: ما ليس من مبانيك يردّ 403 قبل أي كود (كقرار ٨٣)، وغير الموجود 404 كما كان.
 *   - النموذج يعيد تعريف `buildingScope()` إن كان عموده `place_id` (التصريح) أو عبر نموذج آخر (حدث الجهاز عبر جهازه)
 *     أو كان ما بلا مبنى عامّاً (جهات الاتصال — قرار ٨٢؛ الجهاز لأي مبنى).
 *
 * الكتابة لا تُقيَّد: `create` يكتب ما يُعطى. `withoutGlobalScope('building')` للنظام حين يحتاج الكل عن قصد.
 */
trait BelongsToBuilding
{
    public static function bootBelongsToBuilding(): void
    {
        static::addGlobalScope('building', function (Builder $q) {
            $cfg = static::buildingScope();
            $col = $q->getModel()->getTable().'.'.$cfg['column'];
            if ($cfg['via']) {
                if (BuildingScope::buildingIds() === null) return;
                /** @var class-string<\Illuminate\Database\Eloquent\Model> $related */
                $related = $cfg['via'];
                $sub = $related::query()->select((new $related)->getTable().'.id');
                $q->where(fn (Builder $w) => $w->whereIn($col, $sub)->when($cfg['null_general'], fn (Builder $x) => $x->orWhereNull($col)));
                return;
            }
            $ids = $cfg['column'] === 'place_id' ? BuildingScope::placeIds() : BuildingScope::buildingIds();
            if ($ids === null) return;
            $q->where(fn (Builder $w) => $w->whereIn($col, $ids)->when($cfg['null_general'], fn (Builder $x) => $x->orWhereNull($col)));
        });
    }

    /**
     * إعداد النطاق — الافتراض: عمود `building_id`، وما بلا مبنى محجوب، وبلا وسيط.
     * @return array{column: string, null_general: bool, via: class-string|null}
     */
    protected static function buildingScope(): array
    {
        return ['column' => 'building_id', 'null_general' => false, 'via' => null];
    }

    /** حارس الرابط: الكائن موجود وليس من مبانيك ← 403؛ غير موجود ← 404 */
    public function resolveRouteBinding($value, $field = null)
    {
        $m = static::withoutGlobalScope('building')->where($field ?? $this->getRouteKeyName(), $value)->first();
        if ($m === null) return null;
        if (!$m->inAccountBuildings()) abort(403, 'هذا ليس من مبانيك');
        return $m;
    }

    /** هل هذا الصف من مباني الحساب الحالي؟ */
    public function inAccountBuildings(): bool
    {
        $cfg = static::buildingScope();
        $v = $this->getAttribute($cfg['column']);
        if ($cfg['via']) {
            if ($v === null) return $cfg['null_general'] || BuildingScope::buildingIds() === null;
            $rel = $cfg['via']::withoutGlobalScope('building')->find($v);
            return $rel ? $rel->inAccountBuildings() : $cfg['null_general'];
        }
        return $cfg['column'] === 'place_id'
            ? BuildingScope::allowsPlace($v === null ? null : (int) $v, $cfg['null_general'])
            : BuildingScope::allowsBuilding($v === null ? null : (int) $v, $cfg['null_general']);
    }
}
