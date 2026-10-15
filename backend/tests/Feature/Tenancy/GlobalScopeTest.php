<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_query_is_fail_closed_without_context(): void
    {
        Membership::factory()->create();

        // Tanpa konteks organisasi, tidak ada baris tenant yang boleh terlihat.
        $this->assertSame(0, Membership::query()->count());
    }

    public function test_tenant_query_only_returns_context_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        Membership::factory()->count(2)->create(['organization_id' => $organizationA->getKey()]);
        Membership::factory()->create(['organization_id' => $organizationB->getKey()]);

        app(OrganizationContext::class)->set($organizationA);

        $this->assertSame(2, Membership::query()->count());

        // Akses lintas organisasi hanya lewat escape yang eksplisit.
        $this->assertSame(
            1,
            Membership::withoutOrganizationScope()
                ->where('organization_id', $organizationB->getKey())
                ->count(),
        );
    }
}
