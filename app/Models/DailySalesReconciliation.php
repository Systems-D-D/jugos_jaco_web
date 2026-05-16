<?php

namespace App\Models;

use App\Enums\ReconciliationStatusEnum;
use App\Models\TypePrice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailySalesReconciliation extends Model
{
    use HasFactory;

    protected $table = 'daily_sales_reconciliations';

    protected $fillable = [
        'employee_id',
        'cashier_id',
        'branch_id',
        'reconciliation_date',
        'total_credit_sales',
        'total_cash_sales',
        'cash_sales',
        'deposit_sales',
        'total_sales',
        'total_cash_received',
        'total_deposits',
        'total_collections',
        'cash_collections',
        'deposit_collections',
        'total_cash_expected',
        'total_deposit_expected',
        'cash_difference',
        'deposit_difference',
        'notes',
        'status',
        'product_shortage_total',
        'type_price_id',
    ];

    protected $casts = [
        'reconciliation_date' => 'date',
        'total_cash_sales' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'total_credit_sales' => 'decimal:2',
        'deposit_sales' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_cash_received' => 'decimal:2',
        'total_deposits' => 'decimal:2',
        'total_collections' => 'decimal:2',
        'cash_collections' => 'decimal:2',
        'deposit_collections' => 'decimal:2',
        'total_cash_expected' => 'decimal:2',
        'total_deposit_expected' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'deposit_difference' => 'decimal:2',
        'status' => ReconciliationStatusEnum::class,
        'product_shortage_total' => 'decimal:2',
    ];

    /**
     * Check if a reconciliation already exists for the given employee and date.
     */
    public static function existsForEmployeeAndDate(int $employeeId, string $date): bool
    {
        return self::where('employee_id', $employeeId)
            ->whereDate('reconciliation_date', $date)
            ->exists();
    }

    /**
     * Get existing reconciliation for employee and date.
     */
    public static function getForEmployeeAndDate(int $employeeId, string $date): ?self
    {
        return self::where('employee_id', $employeeId)
            ->whereDate('reconciliation_date', $date)
            ->first();
    }

    /**
     * Get the employee that owns the reconciliation.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the cashier (user) that created the reconciliation.
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * Get the branch that the reconciliation belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Get the deposits associated with this reconciliation.
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'model_id');
    }

    /**
     * Get the bills (expenses) associated with this reconciliation.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'model_id');
    }

    /**
     * Get the product returns associated with this reconciliation.
     */
    public function productReturns(): HasMany
    {
        return $this->hasMany(ProductReturn::class, 'reconciliation_id');
    }

    public function typePrice(): BelongsTo
    {
        return $this->belongsTo(TypePrice::class, 'type_price_id');
    }

    /**
     * Calculate the expected cash based on sales and collections.
     */
    public function calculateExpectedCash(): float
    {
        return $this->total_cash_sales + $this->cash_collections - $this->total_deposits;
    }

    /**
     * Calculate the cash difference.
     */
    public function calculateCashDifference(): float
    {
        return $this->total_cash_received - $this->calculateExpectedCash();
    }

    /**
     * Update the status based on cash difference.
     */
    public function updateStatus(): void
    {
        if ($this->cash_difference != 0) {
            $this->status = ReconciliationStatusEnum::WITH_DIFFERENCES;
        } else {
            $this->status = ReconciliationStatusEnum::COMPLETED;
        }
        
        $this->save();
    }

    /**
     * Recalculate all totals and update the record.
     */
    public function recalculateTotals(): void
    {
        // Calculate total sales
        $this->total_sales = $this->total_cash_sales + $this->total_credit_sales;
        
        // Calculate total collections
        $this->total_collections = $this->cash_collections + $this->deposit_collections;
        
        // Calculate expected cash
        $this->total_cash_expected = $this->calculateExpectedCash();
        
        // Calculate cash difference
        $this->cash_difference = $this->calculateCashDifference();
        
        // Update status
        $this->updateStatus();
    }
}