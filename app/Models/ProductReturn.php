<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\ProductReturnTypeEnum;

class ProductReturn extends Model
{
    protected $fillable = [
        'product_id',
        'employee_id',
        'counted_by',
        'detail_assigned_product_id',
        'reconciliation_id',
        'quantity',
        'type',
        'reason',
        'description',
        'affects_inventory',
    ];

    protected $nullable = [
        'reconciliation_id',
        'description',
    ];

    protected $casts = [
        'type' => ProductReturnTypeEnum::class,
        'quantity' => 'decimal:2',
        'affects_inventory' => 'boolean'
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(DailySalesReconciliation::class);
    }

    /** Usuario que registró el conteo (null en devoluciones manuales o anteriores). */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** Detalle de asignación cuyo returned_quantity ajustó esta devolución. */
    public function detailAssignedProduct(): BelongsTo
    {
        return $this->belongsTo(DetailAssignedProduct::class);
    }
}
