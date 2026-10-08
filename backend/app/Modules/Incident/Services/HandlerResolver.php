<?php

namespace App\Modules\Incident\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\DeptSync;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Support\RiskApproval;

/**
 * خطة المعالج — الخطوة ٣ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨): البلاغ يسأل الخطر في السجل العام «من يعالجك؟» — لا إدارةَ المكان.
 * يُقرأ عند كل بلاغ، فتغيير مسؤول السلامة أو مدير الإدارة المعالجة يسري على البلاغ التالي، ولا ينتظر تفعيل الإدارة للخطر.
 *
 * قرار ٨٠ (بكلمته «موافق» ٢٠٢٦-١٠-٠٨) — الترتيب داخل فرع مكان البلاغ:
 *   ٠ معالج نسخة الفرع (كتبه مدير الإدارة المعالجة في الفرع على نسخته): شخص ← هو؛ تخصص ← فني الفرع الذي يغطي المكان
 *   ١ شخص مسمّى في العام                                     ← هو، إن كان في مبنى البلاغ (المسمّى بالاسم يخص مبناه)
 *   ٢ تخصص في العام                                          ← الفني بذلك التخصص الذي يغطي مكان البلاغ
 *   ٣ وإلا                                                     ← مدير الإدارة المعالجة في الفرع: بديل الفرع المعتمد، وإلا الاسم نفسه داخل الفرع، وإلا المركز الرئيسي
 *   ٤ لا إدارة معالجة                                        ← لا حكم للعام (governs=false): المسار القائم ثم المركز بعلّته
 */
final class HandlerResolver
{
    /** الخطر الذي يحكم التوجيه: العام نفسه، أو أصل النسخة المفعّلة */
    public static function governingReference(Risk $risk): ?Risk
    {
        if ($risk->risk_type === 'reference') return $risk;
        return $risk->parent_reference_id ? Risk::find($risk->parent_reference_id) : null;
    }

    /** هل للعام كلمة في معالج هذا الخطر؟ نعم متى كُتبت عليه إدارة معالجة */
    public static function governs(Risk $risk): bool
    {
        $ref = self::governingReference($risk);
        return $ref !== null && ($ref->handling_unit_id || $ref->handling_unit_name);
    }

    /**
     * @return array{governs: bool, user_id: ?int, note: ?string, reason: ?string}
     *   note: ما يُكتب في الخط الزمني · reason: علّة الوقوف عند المركز (حين user_id فارغ)
     */
    public static function resolve(Risk $ref, ?int $placeId): array
    {
        $out = ['governs' => false, 'user_id' => null, 'note' => null, 'reason' => null];
        if ($ref->risk_type !== 'reference') return $out;
        $place = $placeId ? Place::find($placeId) : null;
        $root = $place?->building?->branch_unit_id;
        $copy = $root ? self::branchCopy($ref, (int) $root) : null;
        $unit = self::handlingUnit($ref, $placeId, $copy);
        if (!$unit && !$ref->handling_unit_name) {
            $out['reason'] = 'هذا الخطر بلا إدارة معالجة في السجل العام';
            return $out;
        }
        $out['governs'] = true;
        // ٢٨-٥ (قرار ٧٨): الوحدة المسمّاة في العام وُجدت باسمها داخل فرع مكان البلاغ — تُسمّى بفرعها في الخط الزمني
        $unitName = $unit?->name ?? $ref->handling_unit_name;
        if ($unit && $unit->id !== (int) $ref->handling_unit_id && ($r = self::rootOf($unit))) $unitName .= ' — '.$r->name;
        $placeName = $place?->name ?? 'المكان';

        // ٠ معالج نسخة الفرع (قرار ٨٠)
        if ($copy && $copy->hasOwnHandler()) {
            if ($copy->handler_user_id) {
                $p = UserProfile::where('user_id', $copy->handler_user_id)->where('is_active', true)->first();
                if ($p) {
                    $out['user_id'] = (int) $copy->handler_user_id;
                    $out['note'] = 'من نسخة الفرع: المعالج المسمّى '.self::name($copy->handler_user_id).' (الإدارة المعالجة «'.$unitName.'»)';
                    return $out;
                }
            } elseif ($copy->handler_specialty && $placeId) {
                $techs = self::techniciansCovering($copy->handler_specialty, $placeId);
                if ($techs->isNotEmpty()) {
                    $t = $techs->first();
                    $label = PermissionRegistry::ROLES[$copy->handler_specialty] ?? $copy->handler_specialty;
                    $out['user_id'] = (int) $t->user_id;
                    $out['note'] = 'من نسخة الفرع: '.$label.' الذي يغطي '.$placeName.': '.self::name($t->user_id).' (الإدارة المعالجة «'.$unitName.'»)';
                    return $out;
                }
            }
        }

        // ١ شخص مسمّى في العام — في مبنى البلاغ وحده
        if ($ref->handler_user_id) {
            $p = UserProfile::where('user_id', $ref->handler_user_id)->where('is_active', true)->first();
            if ($p && (!$place || (int) ($p->myBuilding()?->id) === (int) $place->building_id)) {
                $out['user_id'] = (int) $ref->handler_user_id;
                $out['note'] = 'من السجل العام: المعالج المسمّى على الخطر '.self::name($ref->handler_user_id).' (الإدارة المعالجة «'.$unitName.'»)';
                return $out;
            }
        }

        // ٢ تخصص يغطي المكان
        $why = 'لا تخصص ولا شخص على الخطر';
        if ($ref->handler_specialty) {
            $label = PermissionRegistry::ROLES[$ref->handler_specialty] ?? $ref->handler_specialty;
            $techs = $placeId ? self::techniciansCovering($ref->handler_specialty, $placeId) : collect();
            if ($techs->isNotEmpty()) {
                $t = $techs->first();
                $out['user_id'] = (int) $t->user_id;
                $out['note'] = 'من السجل العام: '.$label.' الذي يغطي '.$placeName.': '.self::name($t->user_id)
                    .($techs->count() > 1 ? ' (من '.$techs->count().' يغطون المكان)' : '').' (الإدارة المعالجة «'.$unitName.'»)';
                return $out;
            }
            $why = $placeId ? 'لا '.$label.' يغطي مكان البلاغ' : 'بلاغ بلا مكان فلا يُعرف من يغطيه';
        } elseif ($ref->handler_user_id) {
            $why = 'المعالج المسمّى في العام في مبنى آخر';
        }

        // ٣ مدير الإدارة المعالجة (أو مدير ما فوقها)
        if ($unit) {
            [$mgr, $mgrUnit] = self::managerOf($unit);
            if ($mgr) {
                $out['user_id'] = (int) $mgr->user_id;
                $out['note'] = 'من السجل العام: مدير الإدارة المعالجة «'.($mgrUnit->id === $unit->id ? $unitName : $mgrUnit->name).'» '.self::name($mgr->user_id).' — '.$why;
                return $out;
            }
            $out['reason'] = 'الإدارة المعالجة «'.$unitName.'» بلا مدير بحساب';
            $out['note'] = $out['reason'].' — '.$why.' — بانتظار إحالة المركز';
            return $out;
        }
        $out['reason'] = 'الإدارة المعالجة «'.$unitName.'» لم تعد في الهيكل';
        $out['note'] = $out['reason'].' — بانتظار إحالة المركز';
        return $out;
    }

    /** قرار ٨٠: نسخة الخطر المفعّلة في فرع مكان البلاغ — التي تحمل معالجاً أو بديلاً معتمداً أولاً، ثم الأقدم */
    public static function branchCopy(Risk $ref, int $root): ?Risk
    {
        $copies = Risk::where('risk_type', 'active')->where('parent_reference_id', $ref->id)->where('branch_unit_id', $root)->inEffect()->orderBy('id')->get();
        return $copies->first(fn (Risk $c) => $c->hasOwnHandler())
            ?? $copies->first(fn (Risk $c) => $c->handling_override_state === 'approved')
            ?? $copies->first();
    }

    /**
     * ٢٨-٥ (قرار ٧٨) وقرار ٨٠: الإدارة المعالجة في فرع مكان البلاغ — بديل الفرع المعتمد على نسخته، وإلا باسمها داخل الفرع
     * (نطاق مبنى المكان: فرعه بلا ما تحته من فروع مبانٍ أخرى)، وإلا الوحدة المسمّاة في العام بمعرّفها إن كانت نشطة، وإلا باسمها.
     */
    private static function handlingUnit(Risk $ref, ?int $placeId = null, ?Risk $copy = null): ?OrganizationUnit
    {
        if ($copy && $copy->handling_override_state === 'approved') {
            $o = $copy->handling_unit_id ? OrganizationUnit::find($copy->handling_unit_id) : null;
            if ($o && $o->is_active) return $o;
        }
        $u = $ref->handling_unit_id ? OrganizationUnit::find($ref->handling_unit_id) : null;
        $name = $ref->handling_unit_name ?: $u?->name;
        $place = $placeId ? Place::find($placeId) : null;
        $ids = $place?->building_id ? DeptSync::scopeIds((int) $place->building_id) : null;
        if ($ids !== null && $name) {
            $inBranch = OrganizationUnit::whereIn('id', $ids)->where('name', $name)->where('is_active', true)->orderBy('id')->first();
            if ($inBranch) return $inBranch;
        }
        if ($u && $u->is_active) return $u;
        if ($name) {
            return OrganizationUnit::where('name', $name)->where('is_active', true)->orderBy('id')->first() ?? $u;
        }
        return $u;
    }

    /** رأس شجرة الوحدة (الفرع أو المركز الرئيسي) */
    private static function rootOf(OrganizationUnit $unit): ?OrganizationUnit
    {
        for ($u = $unit, $n = 0; $u && $n < 10; $n++) {
            if (!$u->parent_id) return $u->id === $unit->id ? null : $u;
            $u = OrganizationUnit::find($u->parent_id);
        }
        return null;
    }

    /** فنيون مفعَّلون بهذا التخصص يغطون المكان (أو مكان حسابهم هو وبلا تغطية) — كما في ScopeService::techniciansFor */
    private static function techniciansCovering(string $role, int $placeId)
    {
        return UserProfile::where('role', $role)->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('coverage', fn ($c) => $c->where('places.id', $placeId))
                ->orWhere(fn ($w) => $w->where('place_id', $placeId)->whereDoesntHave('coverage')))
            ->orderBy('user_id')->get();
    }

    /**
     * مدير الوحدة (قرار ٦٩: الأدوار الستة) أو مدير ما فوقها حتى الجذر. قرار ٨٠: الإدارة المعالجة قد تكون مكتباً خارجياً
     * في هيكل الفرع مديره حساب مشرف المقاول أو المكتب الاستشاري — يُقبل على الوحدة نفسها (لا فوقها).
     * @return array{0: ?UserProfile, 1: ?OrganizationUnit}
     */
    private static function managerOf(OrganizationUnit $unit): array
    {
        for ($u = $unit, $n = 0; $u && $n < 10; $u = $u->parent_id ? OrganizationUnit::find($u->parent_id) : null, $n++) {
            $roles = $n === 0 ? array_merge(RiskApproval::UNIT_MANAGERS, ['contractor_supervisor', 'consultant_office']) : RiskApproval::UNIT_MANAGERS;
            $p = UserProfile::whereIn('role', $roles)->where('organization_unit_id', $u->id)->where('is_active', true)->orderBy('user_id')->first();
            if ($p) return [$p, $u];
        }
        return [null, null];
    }

    private static function name(int $userId): string
    {
        return User::find($userId)?->name ?? ('#'.$userId);
    }
}
