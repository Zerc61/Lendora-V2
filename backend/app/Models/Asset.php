<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu baris mewakili satu unit fisik (OD-05). Lifecycle status menggambarkan
 * umur pakai, terpisah dari kondisi fisik dan dari occupancy peminjaman (ADR-002).
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_AWAITING_INSPECTION = 'awaiting_inspection';

    public const LIFECYCLE_UNDER_MAINTENANCE = 'under_maintenance';

    public const LIFECYCLE_RETIRED = 'retired';

    public const CONDITION_EXCELLENT = 'excellent';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_FAIR = 'fair';

    public const CONDITION_POOR = 'poor';

    public const CONDITION_UNSERVICEABLE = 'unserviceable';

    public const MAX_PHOTOS = 10;

    /** @var list<string> */
    public const LIFECYCLE_STATUSES = [
        self::LIFECYCLE_ACTIVE,
        self::LIFECYCLE_AWAITING_INSPECTION,
        self::LIFECYCLE_UNDER_MAINTENANCE,
        self::LIFECYCLE_RETIRED,
    ];

    /** @var list<string> */
    public const CONDITIONS = [
        self::CONDITION_EXCELLENT,
        self::CONDITION_GOOD,
        self::CONDITION_FAIR,
        self::CONDITION_POOR,
        self::CONDITION_UNSERVICEABLE,
    ];

    /** @var array<string, string> */
    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_ACTIVE => 'Tersedia',
        self::LIFECYCLE_AWAITING_INSPECTION => 'Menunggu pemeriksaan',
        self::LIFECYCLE_UNDER_MAINTENANCE => 'Dalam perbaikan',
        self::LIFECYCLE_RETIRED => 'Tidak dipakai lagi',
    ];

    /** @var array<string, string> */
    public const CONDITION_LABELS = [
        self::CONDITION_EXCELLENT => 'Baik sekali',
        self::CONDITION_GOOD => 'Baik',
        self::CONDITION_FAIR => 'Cukup',
        self::CONDITION_POOR => 'Buruk',
        self::CONDITION_UNSERVICEABLE => 'Tidak bisa dipakai',
    ];

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'category_id',
        'location_id',
        'lifecycle_status',
        'condition',
        'serial_number',
        'purchase_date',
        'purchase_price',
        'warranty_until',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
            'warranty_until' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function photos(): MorphMany
    {
        return $this->attachments()->where('type', Attachment::TYPE_PHOTO);
    }

    public function conditionRecords(): HasMany
    {
        return $this->hasMany(AssetConditionRecord::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    /**
     * Ada ticket perbaikan yang masih mengunci aset (Issue & Maintenance E, AL-4).
     */
    public function hasBlockingTicket(): bool
    {
        return $this->maintenanceTickets()
            ->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES)
            ->exists();
    }

    /**
     * Aset bisa dipinjam bila lifecycle-nya aktif. Occupancy peminjaman dihitung
     * dari availability, bukan disimpan di sini (ADR-002).
     */
    public function isBorrowable(): bool
    {
        return $this->lifecycle_status === self::LIFECYCLE_ACTIVE;
    }

    public function conditionLabel(): string
    {
        return self::CONDITION_LABELS[$this->condition] ?? $this->condition;
    }

    public function lifecycleLabel(): string
    {
        return self::LIFECYCLE_LABELS[$this->lifecycle_status] ?? $this->lifecycle_status;
    }

    /**
     * Katalog hanya berisi aset yang bisa ditemukan peminjam. Aset retired
     * tidak muncul sama sekali. Asset Management D.
     */
    public function scopeInCatalog(Builder $builder): Builder
    {
        return $builder->where('lifecycle_status', '!=', self::LIFECYCLE_RETIRED);
    }

    /**
     * Full-text search native PostgreSQL pada nama dan deskripsi (OD-26).
     */
    public function scopeSearch(Builder $builder, string $term): Builder
    {
        return $builder->whereRaw(
            "search_vector @@ plainto_tsquery('simple', ?)",
            [$term],
        );
    }

    public function isRetired(): bool
    {
        return $this->lifecycle_status === self::LIFECYCLE_RETIRED;
    }
}
