<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Project\Models\ExternalParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalParty>
 */
class ExternalPartyFactory extends Factory
{
    protected $model = ExternalParty::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'party_type' => 'contractor',
            'contact_person' => fake()->name(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'cr_number' => fake()->numerify('##########'),
            'status' => 'active',
            'created_by_id' => User::factory(),
        ];
    }

    public function contractor(): static
    {
        return $this->state(fn () => ['party_type' => 'contractor']);
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['party_type' => 'supplier']);
    }
}
