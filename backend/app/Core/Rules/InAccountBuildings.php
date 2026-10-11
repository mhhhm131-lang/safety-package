<?php

namespace App\Core\Rules;

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
 */
final class InAccountBuildings implements ValidationRule
{
    private function __construct(private readonly bool $place) {}

    /** الحقل رقم مبنى (`emergency_buildings.id`) */
    public static function building(): self
    {
        return new self(false);
    }

    /** الحقل رقم مكان (`places.id`) — يُقاس بمبنى المكان */
    public static function place(): self
    {
        return new self(true);
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

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return; // الفارغ وغير الرقمي تحكمهما القواعد التي قبلنا
        }
        $ok = $this->place
            ? BuildingScope::allowsPlace((int) $value)
            : BuildingScope::allowsBuilding((int) $value);
        if (!$ok) {
            $fail($this->place ? 'هذا المكان ليس من مبانيك.' : 'هذا المبنى ليس من مبانيك.');
        }
    }
}
