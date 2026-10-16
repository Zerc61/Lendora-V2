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

    public const VERIFICATION_PENDING = 'pending';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_REJECTED = 'rejected';

    /** @var list<string> */
    public const VERIFICATION_STATUSES = [
        self::VERIFICATION_PENDING,
        self::VERIFICATION_VERIFIED,
        self::VERIFICATION_REJECTED,
    ];

    /** Label bahasa Indonesia status verifikasi; dipakai konsol platform. */
    public const VERIFICATION_LABELS = [
        self::VERIFICATION_PENDING => 'Menunggu verifikasi',
        self::VERIFICATION_VERIFIED => 'Terverifikasi',
        self::VERIFICATION_REJECTED => 'Ditolak',
    ];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
        'settings',
        'description',
        'contact_email',
        'contact_phone',
        'address',
        'city',
        'verification_status',
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

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function verificationStatusLabel(): string
    {
        return self::VERIFICATION_LABELS[$this->verification_status] ?? (string) $this->verification_status;
    }

    /**
     * Provider hanya tampil ke publik bila aktif dan sudah diverifikasi. Dua
     * syarat itu satu tempat, dipakai katalog publik dan resource.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->isActive() && $this->isVerified();
    }

    public function scopePubliclyVisible(Builder $builder): Builder
    {
        return $builder
            ->where('status', self::STATUS_ACTIVE)
            ->where('verification_status', self::VERIFICATION_VERIFIED);
    }
}
