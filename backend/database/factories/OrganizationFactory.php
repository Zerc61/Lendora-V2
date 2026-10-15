<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 99999),
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'settings' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Organization::STATUS_INACTIVE]);
    }
}
