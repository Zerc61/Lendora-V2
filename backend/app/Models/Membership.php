<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role menempel pada membership, bukan pada user. ADR-004.
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToOrganization, HasFactory;

    public const ROLE_BORROWER = 'borrower';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_ADMIN = 'admin';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_INVITED = 'invited';

    /** @var array<string, string> */
    public const ROLE_LABELS = [
        self::ROLE_BORROWER => 'Peminjam',
        self::ROLE_OPERATOR => 'Operator',
        self::ROLE_ADMIN => 'Admin',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Nonaktif',
        self::STATUS_INVITED => 'Diundang',
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
