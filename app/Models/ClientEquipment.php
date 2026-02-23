<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ClientEquipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function image(): MorphOne
    {
        return $this->morphOne(ResourceMedia::class, 'model')->where('type', 'equipment');
    }

    public function getEquipmentImageUrlAttribute(): string
    {
        return $this->image ? asset('storage/' . $this->image->path) : null;
    }
}
