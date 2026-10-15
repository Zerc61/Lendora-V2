<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Issue (Issue & Maintenance B). Pengamatan masalah oleh borrower atau operator.
 * Terpisah dari pekerjaan perbaikan; tidak setiap issue menjadi ticket.
 */
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use BelongsToOrganization, HasFactory;

    public const TYPE_DAMAGE = 'damage';

    public const TYPE_LOSS = 'loss';

    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [self::TYPE_DAMAGE, self::TYPE_LOSS, self::TYPE_OTHER];

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** @var list<string> */
    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    /** Batas jumlah foto bukti (Maintenance B.1). */
    public const MAX_PHOTOS = 10;

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_REVIEWING,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_RESOLVED, self::STATUS_DISMISSED];

    /**
     * Graf transisi (Issue & Maintenance B). Hanya pasangan di sini yang sah;
     * penutupan otomatis oleh sistem ditangani terpisah di service.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_REVIEWING, self::STATUS_DISMISSED],
        self::STATUS_REVIEWING => [self::STATUS_RESOLVED, self::STATUS_DISMISSED, self::STATUS_OPEN],
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_DAMAGE => 'Kerusakan',
        self::TYPE_LOSS => 'Kehilangan',
        self::TYPE_OTHER => 'Lainnya',
    ];

    /** @var array<string, string> */
    public const SEVERITY_LABELS = [
        self::SEVERITY_LOW => 'Rendah',
        self::SEVERITY_MEDIUM => 'Sedang',
        self::SEVERITY_HIGH => 'Tinggi',
        self::SEVERITY_CRITICAL => 'Kritis',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Dilaporkan',
        self::STATUS_REVIEWING => 'Ditinjau',
        self::STATUS_RESOLVED => 'Selesai',
        self::STATUS_DISMISSED => 'Bukan masalah',
    ];

    /**
     * Severity yang mengunci aset saat issue dilaporkan (Issue & Maintenance D).
     * `medium` hanya menuntut inspeksi; `low` tidak mengubah aset.
     *
     * @var array<string, string>
     */
    public const SEVERITY_ASSET_LIFECYCLE = [
        self::SEVERITY_MEDIUM => Asset::LIFECYCLE_AWAITING_INSPECTION,
        self::SEVERITY_HIGH => Asset::LIFECYCLE_UNDER_MAINTENANCE,
        self::SEVERITY_CRITICAL => Asset::LIFECYCLE_UNDER_MAINTENANCE,
    ];

    protected $fillable = [
        'organization_id',
        'asset_id',
        'loan_id',
        'type',
        'severity',
        'status',
        'description',
        'reported_by',
        'assigned_to',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function photos(): MorphMany
    {
        return $this->attachments()->where('type', Attachment::TYPE_PHOTO);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? $this->severity;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
