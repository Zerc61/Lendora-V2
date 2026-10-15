<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Satu-satunya pintu penulisan audit event.
 *
 * Setiap perubahan state memanggil recorder ini di dalam transaksi yang sama.
 * Audit tidak pernah mengubah state domain, hanya mencatatnya. Lihat Audit Strategy.
 */
class AuditRecorder
{
    public function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        array $context = [],
        ?User $actor = null,
        ?Organization $organization = null,
    ): AuditEvent {
        $request = $this->request();
        $actor ??= auth()->user();

        return AuditEvent::create([
            'organization_id' => ($organization ?? $this->organizationFor($subject))?->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'context' => $context === [] ? null : $context,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    /**
     * Percobaan akses yang ditolak tetap dicatat, termasuk lintas organisasi.
     */
    public function accessDenied(string $reason, array $context = []): AuditEvent
    {
        return $this->record(
            action: 'access_denied',
            subject: null,
            context: ['reason' => $reason] + $context,
        );
    }

    private function organizationFor(?Model $subject): ?Organization
    {
        if ($subject !== null && method_exists($subject, 'organization')) {
            $organization = $subject->organization;

            if ($organization instanceof Organization) {
                return $organization;
            }
        }

        return app(OrganizationContext::class)->organization();
    }

    private function request(): ?Request
    {
        $request = app()->bound('request') ? request() : null;

        return $request instanceof Request ? $request : null;
    }
}
