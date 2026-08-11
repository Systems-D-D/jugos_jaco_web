<?php

namespace App\Models;

use App\Enums\AccountReceivableStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AccountReceivable extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_id',
        'name',
        'total_amount',
        'remaining_balance',
        'status',
        'due_date',
        'cancelled_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'status' => AccountReceivableStatusEnum::class,
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
        'paid_at' => 'date',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sales_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'model');
    }

    public function getTotalPaidAttribute(): float
    {
        return $this->total_amount - $this->remaining_balance;
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_amount == 0) {
            return 0;
        }
        
        return ($this->total_paid / $this->total_amount) * 100;
    }

    public function isOverdue(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               $this->status === AccountReceivableStatusEnum::PENDING;
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->whereHas('sale', function($q) use ($employeeId){
            $q->where('employee_id', $employeeId);
        });
    }

    public function scopePaid($query)
    {
        return $query->where('status', AccountReceivableStatusEnum::PAID);
    }

    /**
     * Excluye cuentas por cobrar canceladas (venta anulada, sin pagos). Usar en
     * cualquier consulta que liste o cuente cuentas activas del cliente/vendedor.
     */
    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', AccountReceivableStatusEnum::CANCELLED);
    }
}
