<?php

namespace App\Modules\Form\Support;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;

/**
 * ٢٩-٠: ما يُعبَّأ يحمل إدارته ومبناه من حساب صاحبه (المكلَّف أو المعبِّئ)، لا من المرسل —
 * فتبقى قاعدة الرؤية صادقة حتى حين تُحذف نسبة التعبئة إلى شخص (السرية، ٢٩-٢).
 * المبنى من فرع الوحدة في الهيكل (وثيقة التصميم §٤-٦ البند ١)، وإلا مبنى الحساب، وإلا المبنى الرئيسي.
 */
final class OwnerScope
{
    /** @return array{building_id: int|null, organization_unit_id: int|null} */
    public static function of(?int $userId): array
    {
        $p = $userId ? UserProfile::where('user_id', $userId)->first() : null;
        $unit = $p?->organization_unit_id ? (int) $p->organization_unit_id : null;
        $building = ($unit ? OrganizationUnit::find($unit)?->buildingId() : null)
            ?? $p?->myBuilding()?->id
            ?? EmergencyBuilding::main()?->id;

        return ['building_id' => $building === null ? null : (int) $building, 'organization_unit_id' => $unit];
    }
}
