<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran sewa (Tahap 4, rencana Bagian 6.4).
 *
 * Satu baris per percobaan bayar. `status` hidup di sumbu pembayaran dan tidak
 * mengubah status loan (keputusan 4). Pembayaran saldo dompet langsung `paid`,
 * tetapi `verified_at` terisi baru setelah admin memastikan uangnya masuk.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToOrganization, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu pembayaran',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_REFUNDED => 'Dana dikembalikan',
    ];

    public const METHOD_MIDTRANS = 'midtrans';

    public const METHOD_WALLET = 'wallet';

    protected $fillable = [
        'organization_id',
        'loan_id',
        'order_id',
        'amount',
        'status',
        'expires_at',
        'paid_at',
        'verified_at',
        'verified_by',
        'payload',
        'payment_method',
        'wallet_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Dana sudah diperiksa admin (uang masuk) dan boleh diserahkan. */
    public function isVerified(): bool
    {
        return $this->isPaid() && $this->verified_at !== null;
    }
}
