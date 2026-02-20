<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\AssignedProductMovementTypeEnum;

class AssignedProductMovement extends Model
{
    protected $fillable = [
        'detail_assigned_product_id',
        'type',
        'quantity',
        'note',
        'created_by',
    ];

    protected $casts = [
        'type' => AssignedProductMovementTypeEnum::class,
        'quantity' => 'decimal:2',
    ];

    public function detailAssignedProduct(): BelongsTo
    {
        return $this->belongsTo(DetailAssignedProduct::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
