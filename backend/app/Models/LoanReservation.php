<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LoanReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunci fisik periode (OD-25, Database Plan C.2 Opsi B). Satu baris per
 * (loan, asset) selama loan memblokir. Exclusion constraint menjaga overlap.
 */
class LoanReservation extends Model
{
    /** @use HasFactory<LoanReservationFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'loan_id',
        'asset_id',
        'status',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
