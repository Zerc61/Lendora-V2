<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MaintenanceTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MaintenanceTicket (Issue & Maintenance C). Pekerjaan perbaikan aset.
 *
 * State machine memisahkan "pekerjaan selesai" (`awaiting_verification`) dari
 * "aset dinyatakan layak" (`resolved`), sehingga perbaikan yang tidak benar tidak
 * bisa mengembalikan aset ke `active` tanpa pemeriksaan.
 */
class MaintenanceTicket extends Model
{
    /** @use HasFactory<MaintenanceTicketFactory> */
    use BelongsToOrganization, HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AWAITING_PARTS = 'awaiting_parts';

    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_AWAITING_PARTS,
        self::STATUS_AWAITING_VERIFICATION,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    /**
     * State yang mengunci aset dari peminjaman (Issue & Maintenance E).
     *
     * @var list<string>
     */
    public const BLOCKING_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_AWAITING_PARTS,
        self::STATUS_AWAITING_VERIFICATION,
    ];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_RESOLVED, self::STATUS_CANCELLED];

    /**
     * Graf transisi (Issue & Maintenance C). Hanya pasangan di sini yang sah
     * (Business Rules T-1).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_ASSIGNED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_AWAITING_PARTS,
            self::STATUS_AWAITING_VERIFICATION,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_AWAITING_PARTS => [self::STATUS_CANCELLED],
        self::STATUS_AWAITING_VERIFICATION => [self::STATUS_RESOLVED, self::STATUS_IN_PROGRESS],
    ];

    /**
     * State ticket terhadap lifecycle aset (Issue & Maintenance C dan H).
     *
     * @var array<string, string>
     */
    public const STATUS_ASSET_LIFECYCLE = [
        self::STATUS_OPEN => Asset::LIFECYCLE_UNDER_MAINTENANCE,
        self::STATUS_ASSIGNED => Asset::LIFECYCLE_UNDER_MAINTENANCE,
        self::STATUS_IN_PROGRESS => Asset::LIFECYCLE_UNDER_MAINTENANCE,
        self::STATUS_AWAITING_PARTS => Asset::LIFECYCLE_UNDER_MAINTENANCE,
        self::STATUS_AWAITING_VERIFICATION => Asset::LIFECYCLE_AWAITING_INSPECTION,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Baru dibuat',
        self::STATUS_ASSIGNED => 'Ditugaskan',
        self::STATUS_IN_PROGRESS => 'Sedang dikerjakan',
        self::STATUS_AWAITING_PARTS => 'Menunggu komponen',
        self::STATUS_AWAITING_VERIFICATION => 'Menunggu verifikasi',
        self::STATUS_RESOLVED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Prioritas memakai nilai severity issue, boleh diubah operator. */
    public const PRIORITIES = Issue::SEVERITIES;

    /** Label prioritas memakai label severity issue yang sama. */
    public const PRIORITY_LABELS = Issue::SEVERITY_LABELS;

    protected $fillable = [
        'organization_id',
        'asset_id',
        'issue_id',
        'status',
        'priority',
        'scheduled_start',
        'scheduled_end',
        'assigned_to',
        'description',
        'estimated_cost',
        'actual_cost',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'ticket_id');
    }

    public function conditionRecords(): HasMany
    {
        return $this->hasMany(AssetConditionRecord::class, 'ticket_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
    }

    /**
     * Lifecycle aset untuk state saat ini. `null` berarti state tidak mengubah
     * aset (dipakai `resolved` dan `cancelled`, yang ditangani terpisah).
     */
    public function assetLifecycle(): ?string
    {
        return self::STATUS_ASSET_LIFECYCLE[$this->status] ?? null;
    }
}
