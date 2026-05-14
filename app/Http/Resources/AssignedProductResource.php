<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignedProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product->id,
            'productName' => $this->product->name,
            'content_type' => $this->product->content_type,
            'content' => $this->product->content,
            'productCode' => $this->product->code,
            'quantity' => $this->quantity,
            'sale_quantity' => $this->sale_quantity,
            'stock' => $this->stock,
            'royalties_quantity' => $this->royalties_quantity,
            'changes_quantity' => $this->changes_quantity,
        ];
    }
}
