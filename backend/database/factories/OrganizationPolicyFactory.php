<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationPolicy>
 */
class OrganizationPolicyFactory extends Factory
{
    protected $model = OrganizationPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
        ] + OrganizationPolicy::defaults();
    }
}
