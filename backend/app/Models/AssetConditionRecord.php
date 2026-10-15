<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AssetConditionRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Riwayat kondisi aset, append-only (Condition & Inspection, D-3).
 *
 * Satu baris per pemeriksaan; tidak pernah diperbarui. `assets.condition`
 * hanya cache dari baris terbaru (C-1).
 */
class AssetConditionRecord extends Model
{
    /** @use HasFactory<AssetConditionRecordFactory> */
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    /** Sumber record: kapan kondisi dicatat. */
    public const SOURCE_CHECKOUT = 'checkout';

    public const SOURCE_CHECKIN = 'checkin';

    public const SOURCE_STANDALONE = 'standalone';

    public const SOURCE_MAINTENANCE = 'maintenance';

    public const SOURCES = [
        self::SOURCE_CHECKOUT,
        self::SOURCE_CHECKIN,
        self::SOURCE_STANDALONE,
        self::SOURCE_MAINTENANCE,
    ];

    /** Kondisi yang wajib disertai catatan (C-6). */
    public const NOTES_REQUIRED_CONDITIONS = [
        Asset::CONDITION_POOR,
        Asset::CONDITION_UNSERVICEABLE,
    ];

    /** Kondisi yang menahan peminjaman berikutnya (C-3). */
    public const BLOCKING_CONDITIONS = [
        Asset::CONDITION_POOR,
        Asset::CONDITION_UNSERVICEABLE,
    ];

    protected $fillable = [
        'organization_id',
        'asset_id',
        'condition',
        'source',
        'notes',
        'checklist_result',
        'inspected_by',
        'inspected_at',
        'loan_id',
        'ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'checklist_result' => 'array',
            'inspected_at' => 'datetime',
            'created_at' => 'datetime',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'ticket_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function conditionLabel(): string
    {
        return Asset::CONDITION_LABELS[$this->condition] ?? $this->condition;
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    /** Label UI untuk setiap sumber (Business Rules B). */
    public const SOURCE_LABELS = [
        self::SOURCE_CHECKOUT => 'Serah terima',
        self::SOURCE_CHECKIN => 'Pengembalian',
        self::SOURCE_STANDALONE => 'Pemeriksaan mandiri',
        self::SOURCE_MAINTENANCE => 'Verifikasi perbaikan',
    ];
}
