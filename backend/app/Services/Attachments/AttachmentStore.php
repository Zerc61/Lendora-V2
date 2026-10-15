<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Satu-satunya pintu penyimpanan foto lampiran. File disimpan di disk melalui
 * abstraksi filesystem (OD-18) dan disajikan lewat endpoint terautorisasi.
 */
class AttachmentStore
{
    /**
     * @param  list<UploadedFile>  $photos
     */
    public function storePhotos(Model $owner, array $photos): void
    {
        foreach ($photos as $file) {
            $path = $file->store('attachments/'.$owner->organization_id);

            Attachment::query()->create([
                'attachable_type' => $owner->getMorphClass(),
                'attachable_id' => $owner->getKey(),
                'type' => Attachment::TYPE_PHOTO,
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }
}
