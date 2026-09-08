<?php

namespace Database\Factories;

use App\Modules\Worker\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    protected $model = Trade::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->randomElement(['لحّام', 'كهربائي', 'سبّاك', 'نجّار', 'ميكانيكي']) . ' ' . fake()->numberBetween(1, 999),
            'level' => 'occupation',
            'is_active' => true,
        ];
    }

    public function major(): static
    {
        return $this->state(fn () => ['level' => 'major']);
    }
}
