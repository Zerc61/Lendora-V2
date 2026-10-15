<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'password', 'phone', 'avatar_path', 'status',
    'student_id', 'city', 'education_status', 'address',
    'birth_place', 'birth_date', 'emergency_contact_name', 'emergency_contact_phone',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu aktivasi',
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Nonaktif',
    ];

    public const EDUCATION_SMP = 'siswa_smp';

    public const EDUCATION_SMK = 'siswa_smk';

    public const EDUCATION_SMA = 'siswa_sma';

    public const EDUCATION_MAHASISWA = 'mahasiswa';

    public const EDUCATION_UMUM = 'umum';

    /** @var list<string> */
    public const EDUCATION_STATUSES = [
        self::EDUCATION_SMP,
        self::EDUCATION_SMK,
        self::EDUCATION_SMA,
        self::EDUCATION_MAHASISWA,
        self::EDUCATION_UMUM,
    ];

    /** @var array<string, string> */
    public const EDUCATION_LABELS = [
        self::EDUCATION_SMP => 'Siswa SMP',
        self::EDUCATION_SMK => 'Siswa SMK',
        self::EDUCATION_SMA => 'Siswa SMA',
        self::EDUCATION_MAHASISWA => 'Mahasiswa',
        self::EDUCATION_UMUM => 'Umum',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'birth_date' => 'date',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Membership aktif. Query sengaja melewati global scope tenant karena dipakai
     * untuk menentukan konteks organisasi sebelum konteks itu sendiri ada.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'borrower_id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()
            ->withoutOrganizationScope()
            ->where('status', Membership::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function educationStatusLabel(): ?string
    {
        return $this->education_status !== null
            ? (self::EDUCATION_LABELS[$this->education_status] ?? $this->education_status)
            : null;
    }
}
