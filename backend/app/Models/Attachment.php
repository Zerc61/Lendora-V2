<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lampiran polimorfik (foto aset, issue, atau tiket). Tanpa updated_at.
 * File disimpan di disk melalui abstraksi filesystem (OD-18).
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_PHOTO = 'photo';

    protected $fillable = [
        'organization_id',
        'attachable_type',
        'attachable_id',
        'type',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
