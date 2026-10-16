<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    public const TYPE_TOPUP = 'topup';

    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_LABELS = [
        self::TYPE_TOPUP => 'Top up',
        self::TYPE_DEDUCTION => 'Pembayaran',
        self::TYPE_REFUND => 'Pengembalian dana',
        self::TYPE_ADJUSTMENT => 'Penyesuaian',
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu pembayaran',
        self::STATUS_SUCCESS => 'Berhasil',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_REVERSED => 'Dibatalkan',
    ];

    /** Jenis yang menambah saldo; sisanya mengurangi. */
    public const TYPES_IN = [self::TYPE_TOPUP, self::TYPE_REFUND];

    /** Jenis yang mengurangi saldo. */
    public const TYPES_OUT = [self::TYPE_DEDUCTION, self::TYPE_ADJUSTMENT];

    /** Semua jenis yang sah, dipakai validasi filter. */
    public const TYPES = [self::TYPE_TOPUP, self::TYPE_DEDUCTION, self::TYPE_REFUND, self::TYPE_ADJUSTMENT];

    /** Semua status yang sah, dipakai validasi filter. */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_REVERSED];

    protected $fillable = [
        'user_id',
        'amount',
        'type',
        'reference_id',
        'status',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
