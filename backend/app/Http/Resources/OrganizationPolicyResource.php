<?php

namespace App\Http\Resources;

use App\Models\OrganizationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationPolicy
 */
class OrganizationPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization_id' => $this->organization_id,
            'max_loan_duration_days' => $this->max_loan_duration_days
                ?? OrganizationPolicy::DEFAULT_MAX_LOAN_DURATION_DAYS,
            'min_loan_duration_hours' => $this->min_loan_duration_hours
                ?? OrganizationPolicy::DEFAULT_MIN_LOAN_DURATION_HOURS,
            'max_advance_days' => $this->max_advance_days
                ?? OrganizationPolicy::DEFAULT_MAX_ADVANCE_DAYS,
            'min_notice_hours' => $this->min_notice_hours
                ?? OrganizationPolicy::DEFAULT_MIN_NOTICE_HOURS,
            'pickup_window_hours' => $this->pickup_window_hours
                ?? OrganizationPolicy::DEFAULT_PICKUP_WINDOW_HOURS,
            'max_active_loans' => $this->max_active_loans
                ?? OrganizationPolicy::DEFAULT_MAX_ACTIVE_LOANS,
            'require_approval' => $this->require_approval
                ?? OrganizationPolicy::DEFAULT_REQUIRE_APPROVAL,
            'require_prepayment' => $this->require_prepayment
                ?? OrganizationPolicy::DEFAULT_REQUIRE_PREPAYMENT,
            'require_pre_checkout_inspection' => $this->require_pre_checkout_inspection
                ?? OrganizationPolicy::DEFAULT_REQUIRE_PRE_CHECKOUT_INSPECTION,
            'require_post_return_inspection' => $this->require_post_return_inspection
                ?? OrganizationPolicy::DEFAULT_REQUIRE_POST_RETURN_INSPECTION,
            // allow_renewal sengaja tidak diekspor: fitur perpanjangan belum
            // ada end-to-end; kolom tetap di database untuk nanti.
            'allow_borrower_cancel' => $this->allow_borrower_cancel
                ?? OrganizationPolicy::DEFAULT_ALLOW_BORROWER_CANCEL,
            'maintenance_notice_days' => $this->maintenance_notice_days
                ?? OrganizationPolicy::DEFAULT_MAINTENANCE_NOTICE_DAYS,
        ];
    }
}
