<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris selalu satu unit aset (OD-05). Tidak ada soft delete; loan tidak
 * pernah dihapus, status terminal adalah arsipnya.
 */
class LoanItem extends Model
{
    protected $fillable = [
        'loan_id',
        'asset_id',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
