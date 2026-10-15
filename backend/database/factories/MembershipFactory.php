<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => Membership::ROLE_BORROWER,
            'status' => Membership::STATUS_ACTIVE,
            'joined_at' => now(),
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(Membership::ROLE_ADMIN);
    }

    public function operator(): static
    {
        return $this->role(Membership::ROLE_OPERATOR);
    }

    public function invited(): static
    {
        return $this->state(fn () => ['status' => Membership::STATUS_INVITED, 'joined_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Membership::STATUS_INACTIVE]);
    }
}
