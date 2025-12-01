<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HoldResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'hold_id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
