<?php

namespace App\Core\Rules;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Services\BuildingScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * حارس الكتابة (تتمة قرار ٨٤ — بكلمته «تمام» ٢٠٢٦-١٠-١١): «المبنى/المكان من مبانيك».
 *
 * النطاق العام في `BelongsToBuilding` يقيّد القراءة لا الإدراج: نموذج يحمل رقم مبنى آخر في جسمه كان يمرّ
 * (كشك الزوار بـ`building_id` الملز من حساب الدمام، تصريح بـ`place_id` الملز…) لأن التحقق يسأل `exists:`
 * «هل هو موجود؟» لا «هل هو من مبانيك؟». هذه القاعدة تسأل السؤال الثاني في موضع واحد، وتُلحق بكل حقل
 * `building_id` أو `place_id` يأتي من جسم الطلب في مواضع الإنشاء والتعديل.
 *
 *   - بلا مستخدم (الطابور، البذر) أو لمن يرى الكل: تمرّ (`BuildingScope` يجيب null).
 *   - الفارغ لا يصل إليها (nullable/required يحكمانه)؛ والمبنى الفارغ معناه «عامّ» حيث يقرّره النموذج.
 *   - غير الموجود يردّه `exists:` قبلها؛ والموجود خارج مبانيك تردّه هي بـ422 ورسالة تحت الحقل (أو في JSON).
 *
 * ٢٩-٠ (قرار ٨٧): و«الوحدة من مبانيك» — حقل `organization_unit_id`/`unit_id` يُقاس بمبنى الوحدة من فرعها في الهيكل
 * (`OrganizationUnit::buildingId`): الإرسال إلى وحدة، وفتح جولة لوحدة (٢٩-٤).
 */
final class InAccountBuildings implements ValidationRule
{
    private const BUILDING = 'building';
    private const PLACE    = 'place';
    private const UNIT     = 'unit';

    private function __construct(private readonly string $kind) {}

    /** الحقل رقم مبنى (`emergency_buildings.id`) */
    public static function building(): self
    {
        return new self(self::BUILDING);
    }

    /** الحقل رقم مكان (`places.id`) — يُقاس بمبنى المكان */
    public static function place(): self
    {
        return new self(self::PLACE);
    }

    /** الحقل رقم وحدة تنظيمية (`organization_units.id`) — يُقاس بمبنى الوحدة من فرعها */
    public static function unit(): self
    {
        return new self(self::UNIT);
    }

    /** قواعد حقل المبنى كاملة: الوجود ثم النطاق — مثال `InAccountBuildings::buildingRules('required')` */
    public static function buildingRules(string ...$presence): array
    {
        return [...$presence, 'integer', 'exists:emergency_buildings,id', self::building()];
    }

    /** قواعد حقل المكان كاملة: الوجود ثم النطاق — مثال `InAccountBuildings::placeRules('nullable')` */
    public static function placeRules(string ...$presence): array
    {
        return [...$presence, 'integer', 'exists:places,id', self::place()];
    }

    /** قواعد حقل الوحدة كاملة: الوجود ثم النطاق — مثال `InAccountBuildings::unitRules('required_if:mode,unit', 'nullable')` */
    public static function unitRules(string ...$presence): array
    {
        return [...$presence, 'integer', 'exists:organization_units,id', self::unit()];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return; // الفارغ وغير الرقمي تحكمهما القواعد التي قبلنا
        }
        $ok = match ($this->kind) {
            self::PLACE => BuildingScope::allowsPlace((int) $value),
            self::UNIT  => BuildingScope::allowsBuilding(OrganizationUnit::find((int) $value)?->buildingId()),
            default     => BuildingScope::allowsBuilding((int) $value),
        };
        if (!$ok) {
            $fail(match ($this->kind) {
                self::PLACE => 'هذا المكان ليس من مبانيك.',
                self::UNIT  => 'هذه الوحدة ليست من مبانيك.',
                default     => 'هذا المبنى ليس من مبانيك.',
            });
        }
    }
}
