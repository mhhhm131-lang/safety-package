<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worker>
 */
class WorkerFactory extends Factory
{
    protected $model = Worker::class;

    public function definition(): array
    {
        return [
            'external_party_id' => ExternalParty::factory(),
            'full_name' => fake()->name(),
            'national_id' => fake()->unique()->numerify('##########'),
            'phone' => fake()->phoneNumber(),
            'trade_id' => Trade::factory(),
            'status' => 'draft',
            'joined_date' => now()->subMonths(2),
            'created_by_id' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved']);
    }

    public function workAuthorized(): static
    {
        return $this->state(fn () => ['status' => 'work_authorized']);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => 'blocked',
            'blocked_reason' => fake()->sentence(),
        ]);
    }
}
