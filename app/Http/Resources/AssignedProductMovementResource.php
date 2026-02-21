<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignedProductMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->detailAssignedProduct->product_id,
            'product_name' => $this->detailAssignedProduct->product->name,
            'type' => $this->type->getLabel(),
            'quantity' => $this->quantity,
            'note' => $this->note,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
