<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Aggregate Loan, satu state machine (ADR-001, Lending Lifecycle).
 *
 * Tujuh state, empat terminal. Nilai berbasis waktu (`is_overdue`, `is_expired`,
 * `days_until_due`) dihitung saat baca, tidak disimpan (ADR-003).
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToOrganization, HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_RETURNED = 'returned';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_RETURNED,
    ];

    /** @var list<string> */
    public const BLOCKING_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_CHECKED_OUT,
    ];

    /**
     * Graf transisi (Lending Lifecycle C). Hanya pasangan di sini yang dianggap
     * sah; semua pasangan lain ditolak (Testing Strategy D, T-1).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_CHECKED_OUT,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ],
        self::STATUS_CHECKED_OUT => [
            self::STATUS_RETURNED,
        ],
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_CHECKED_OUT,
        self::STATUS_RETURNED,
    ];

    /**
     * Label bahasa manusia. Nilai mentah tidak pernah tampil (Business Rules B).
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_REQUESTED => 'Menunggu persetujuan',
        self::STATUS_APPROVED => 'Disetujui, menunggu diambil',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_CANCELLED => 'Dibatalkan',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CHECKED_OUT => 'Sedang dipinjam',
        self::STATUS_RETURNED => 'Sudah dikembalikan',
    ];

    protected $fillable = [
        'organization_id',
        'borrower_id',
        'status',
        'starts_at',
        'ends_at',
        'due_at',
        'purpose',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'cancellation_reason',
        'checked_out_at',
        'checked_out_by',
        'returned_at',
        'returned_by',
        'is_late',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'due_at' => 'datetime',
            'approved_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'returned_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LoanReservation::class);
    }

    public function conditionRecords(): HasMany
    {
        return $this->hasMany(AssetConditionRecord::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function label(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    /**
     * Loan `approved` yang sudah melewati jendela pengambilan. Turunan, dihitung
     * saat baca, tidak disimpan (ADR-003, CS-05).
     */
    public function isExpired(int $pickupWindowHours): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->approved_at !== null
            && now()->greaterThan($this->pickupDeadline($pickupWindowHours));
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_CHECKED_OUT
            && $this->due_at !== null
            && now()->greaterThan($this->due_at);
    }

    public function isAwaitingPickup(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function pickupDeadline(int $pickupWindowHours): ?Carbon
    {
        return $this->approved_at?->copy()->addHours($pickupWindowHours);
    }

    public function daysUntilDue(): ?int
    {
        if ($this->due_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_at->copy()->startOfDay(), false);
    }
}
