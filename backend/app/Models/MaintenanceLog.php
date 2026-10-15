<?php

namespace App\Models;

use Database\Factories\MaintenanceLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MaintenanceLog (Issue & Maintenance G). Append-only; setiap perubahan state
 * ticket menambah satu baris (M-7). Tanpa `organization_id` karena selalu
 * diakses lewat ticket yang sudah tersaring tenant.
 */
class MaintenanceLog extends Model
{
    /** @use HasFactory<MaintenanceLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'ticket.created';

    public const ACTION_ASSIGNED = 'ticket.assigned';

    public const ACTION_STARTED = 'ticket.started';

    public const ACTION_AWAITING_PARTS = 'ticket.awaiting_parts';

    public const ACTION_COMPLETED = 'ticket.completed';

    public const ACTION_VERIFIED = 'ticket.verified';

    public const ACTION_VERIFICATION_FAILED = 'ticket.verification_failed';

    public const ACTION_CANCELLED = 'ticket.cancelled';

    /** @var array<string, string> */
    public const ACTION_LABELS = [
        self::ACTION_CREATED => 'Ticket dibuat',
        self::ACTION_ASSIGNED => 'Teknisi ditugaskan',
        self::ACTION_STARTED => 'Pengerjaan dimulai',
        self::ACTION_AWAITING_PARTS => 'Menunggu komponen',
        self::ACTION_COMPLETED => 'Pengerjaan selesai',
        self::ACTION_VERIFIED => 'Diverifikasi',
        self::ACTION_VERIFICATION_FAILED => 'Verifikasi gagal',
        self::ACTION_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'ticket_id',
        'actor_id',
        'action',
        'note',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
