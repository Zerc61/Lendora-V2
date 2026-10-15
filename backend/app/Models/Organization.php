<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant root. Tidak memakai global scope tenant karena dirinya adalah tenant.
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function policy(): HasOne
    {
        return $this->hasOne(OrganizationPolicy::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            Membership::class,
            'organization_id',
            'id',
            'id',
            'user_id',
        );
    }

    /**
     * Organisasi yang bisa dilihat seorang user: hanya yang punya membership aktif.
     * Query membership sengaja melewati global scope karena ini penentu konteks.
     */
    public function scopeVisibleTo(Builder $builder, User $user): Builder
    {
        return $builder->whereIn('organizations.id', Membership::withoutOrganizationScope()
            ->where('user_id', $user->getKey())
            ->where('status', Membership::STATUS_ACTIVE)
            ->select('organization_id'));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
