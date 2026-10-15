<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelompok aset. Nama unik per organisasi di antara kategori yang masih hidup.
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
