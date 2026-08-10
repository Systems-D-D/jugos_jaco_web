<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailAssignedProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'sale_quantity',
        'returned_quantity',
        'changes_quantity',
        'royalties_quantity',
        'assigned_products_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'sale_quantity' => 'decimal:2',
        'returned_quantity' => 'decimal:2',
        'changes_quantity' => 'decimal:2',
        'royalties_quantity' => 'decimal:2',
    ];


    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedProduct(): BelongsTo
    {
        return $this->belongsTo(AssignedProduct::class, 'assigned_products_id');
    }

    public function movements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssignedProductMovement::class);
    }

    /**
     * Get the stock attribute.
     *
     * @return int
     */
    protected function stock(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->quantity - ($this->sale_quantity + $this->returned_quantity + $this->changes_quantity + $this->royalties_quantity),
        );
    }
}
