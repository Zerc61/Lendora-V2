<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basis test katalog: menyiapkan organisasi aktif dan anggota dengan role.
 * Konteks organisasi dibentuk middleware dari membership aktif, jadi helper di
 * sini hanya perlu membuat membership yang benar.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Cache kebijakan bersifat per proses; buang agar tidak bocor antar test.
        OrganizationPolicy::flushResolved();

        $this->organization = Organization::factory()->create();
    }

    protected function userIn(Organization $organization, string $role): User
    {
        $user = User::factory()->create();

        Membership::factory()->role($role)->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return $user;
    }

    protected function member(string $role): User
    {
        return $this->userIn($this->organization, $role);
    }

    protected function category(?Organization $organization = null): Category
    {
        return Category::factory()->create([
            'organization_id' => ($organization ?? $this->organization)->getKey(),
        ]);
    }

    protected function location(?Organization $organization = null): Location
    {
        return Location::factory()->create([
            'organization_id' => ($organization ?? $this->organization)->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function asset(?Organization $organization = null, array $attributes = []): Asset
    {
        $organization ??= $this->organization;

        return Asset::factory()->create([
            'organization_id' => $organization->getKey(),
            'category_id' => $this->category($organization)->getKey(),
        ] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function policy(array $attributes = [], ?Organization $organization = null): OrganizationPolicy
    {
        return OrganizationPolicy::factory()->create([
            'organization_id' => ($organization ?? $this->organization)->getKey(),
        ] + $attributes);
    }
}
