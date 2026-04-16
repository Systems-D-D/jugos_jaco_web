<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_code' => $this->product_code,
            'unit_name' => $this->unit_name,
            'unit_abbreviation' => $this->unit_abbreviation,
            'quantity' => (float) $this->quantity,
            'tax_category_name' => $this->tax_category_name,
            'tax_rate' => (float) $this->tax_rate,
            'line_subtotal' => (float) $this->line_subtotal,
            'line_tax_amount' => (float) $this->line_tax_amount,
            'line_total' => (float) $this->line_total,
            'price_include_tax' => (bool) $this->price_include_tax,
            'discount_percentage' => (float) $this->discount_percentage,
            'discount_amount' => (float) $this->discount_amount,
        ];
    }
}
