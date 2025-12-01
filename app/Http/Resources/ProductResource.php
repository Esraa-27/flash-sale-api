<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Handle array data (from findWithAvailableStock which returns array)
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'price' => $this->resource['price'],
            'total_stock' => $this->resource['total_stock'],
            'available_stock' => $this->resource['available_stock'],
        ];
    }
}
