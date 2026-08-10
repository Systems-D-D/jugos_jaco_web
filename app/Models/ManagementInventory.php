<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ManagementInventory extends Model
{
    use HasFactory;

    protected $table = 'management_inventory';

    protected $fillable = [
        'description',
        'quantity',
        'type',
        'model_type',
        'model_id',
        'reference_id',
        'reference_type',
        'created_by'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
