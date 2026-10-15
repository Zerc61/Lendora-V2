<?php

namespace App\Http\Resources;

use App\Models\LoanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanItem
 */
class LoanItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset' => new AssetResource($this->whenLoaded('asset')),
        ];
    }
}
