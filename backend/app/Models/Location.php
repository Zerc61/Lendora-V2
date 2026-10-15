<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tempat penyimpanan aset, berhierarki maksimal empat tingkat. Asset Management C.8.
 * Level diisi otomatis dari parent_id, bukan dari input user.
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const MAX_LEVEL = 4;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'name',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public static function levelFor(?self $parent): int
    {
        return $parent === null ? 1 : $parent->level + 1;
    }

    /**
     * Kedalaman anak terjauh di bawah simpul ini. Dipakai saat memindahkan simpul
     * agar subhierarkinya tidak melewati MAX_LEVEL.
     */
    public function subtreeHeight(): int
    {
        $children = $this->children()->get();

        if ($children->isEmpty()) {
            return 0;
        }

        return 1 + $children->max(fn (self $child): int => $child->subtreeHeight());
    }
}
