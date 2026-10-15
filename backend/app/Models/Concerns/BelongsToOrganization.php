<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation di level query. ADR-004.
 *
 * - Setiap model tenant otomatis difilter `organization_id` dari konteks aktif.
 * - Bila konteks kosong, query fail-closed (tidak mengembalikan baris).
 * - `organization_id` diisi otomatis saat membuat baris.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $context = app(OrganizationContext::class);
            $column = $builder->getModel()->qualifyColumn('organization_id');

            if ($context->check()) {
                $builder->where($column, $context->id());
            } else {
                // Fail-closed: tanpa konteks, tidak ada baris tenant yang terlihat.
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $context = app(OrganizationContext::class);

                if ($context->check()) {
                    $model->setAttribute('organization_id', $context->id());
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Query lintas organisasi harus eksplisit. ADR-004.
     */
    public function scopeWithoutOrganizationScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope('organization');
    }
}
