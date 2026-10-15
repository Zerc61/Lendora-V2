<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationPolicyResource;
use App\Models\Organization;
use App\Models\OrganizationPolicy;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan organisasi. Admin-only: hanya admin organisasi yang bisa melihat
 * dan memperbarui kebijakan. Endpoint GET /organizations/{id}/policy dan
 * PATCH /organizations/{id}/policy.
 */
class OrganizationPolicyController extends Controller
{
    public function show(Request $request, int $organization): OrganizationPolicyResource
    {
        $org = Organization::query()
            ->visibleTo($request->user())
            ->findOrFail($organization);

        $this->authorize('viewPolicy', $org);

        $policy = OrganizationPolicy::resolve($org->id);

        return new OrganizationPolicyResource($policy);
    }

    public function update(
        Request $request,
        int $organization,
        AuditRecorder $audit,
    ): OrganizationPolicyResource {
        $org = Organization::query()
            ->visibleTo($request->user())
            ->findOrFail($organization);

        $this->authorize('updatePolicy', $org);

        $data = $request->validate([
            'max_loan_duration_days' => 'sometimes|integer|min:1|max:365',
            'min_loan_duration_hours' => 'sometimes|integer|min:1|max:720',
            'max_advance_days' => 'sometimes|integer|min:1|max:365',
            'min_notice_hours' => 'sometimes|integer|min:1|max:720',
            'pickup_window_hours' => 'sometimes|integer|min:1|max:720',
            'max_active_loans' => 'sometimes|integer|min:1|max:100',
            'require_approval' => 'sometimes|boolean',
            'require_pre_checkout_inspection' => 'sometimes|boolean',
            'require_post_return_inspection' => 'sometimes|boolean',
            'allow_borrower_cancel' => 'sometimes|boolean',
            'maintenance_notice_days' => 'sometimes|integer|min:1|max:30',
        ]);

        $policy = DB::transaction(function () use ($org, $data, $audit): OrganizationPolicy {
            $model = OrganizationPolicy::query()
                ->withoutOrganizationScope()
                ->firstOrCreate(['organization_id' => $org->id]);

            $before = $model->only(array_keys($data));
            $model->fill($data)->save();

            $audit->record(
                action: 'organization_policy.updated',
                subject: $org,
                before: $before,
                after: $data,
            );

            OrganizationPolicy::flushResolved();

            return $model;
        });

        return new OrganizationPolicyResource($policy);
    }
}
