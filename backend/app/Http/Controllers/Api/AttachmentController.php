<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran disajikan lewat endpoint terautorisasi, bukan URL publik, supaya
 * file tetap privat dan terisolasi per organisasi. OD-18.
 */
class AttachmentController extends Controller
{
    public function show(int $attachment): StreamedResponse
    {
        $model = Attachment::query()->findOrFail($attachment);

        $this->authorize('view', $model);

        $disk = Storage::disk();

        abort_unless($disk->exists($model->path), 404);

        return $disk->download($model->path, basename($model->path));
    }
}
