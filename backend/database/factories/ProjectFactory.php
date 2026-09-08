<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Project\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['مشروع النفق', 'مشروع الجسر', 'مشروع المبنى', 'مشروع الطريق']) . ' ' . fake()->numberBetween(1, 999),
            'code' => 'PRJ-' . fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'status' => 'active',
            'start_date' => now()->subDays(30),
            'end_date' => now()->addMonths(6),
            'created_by_id' => User::factory(),
        ];
    }

    public function planning(): static
    {
        return $this->state(fn () => ['status' => 'planning']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }
}
