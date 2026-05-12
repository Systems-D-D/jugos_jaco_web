<?php

use App\Models\AssignedProductMovement;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('has sale belongsTo relationship', function () {
    $movement = new AssignedProductMovement();
    $relation = $movement->sale();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Sale::class);
});

it('has sale_id in fillable', function () {
    $movement = new AssignedProductMovement();

    expect($movement->getFillable())->toContain('sale_id');
});

it('casts sale_id to integer', function () {
    $movement = new AssignedProductMovement();

    expect($movement->getCasts()['sale_id'])->toBe('integer');
});

it('Sale has assignedProductMovements hasMany relationship', function () {
    $sale = new Sale();
    $relation = $sale->assignedProductMovements();

    expect($relation)->toBeInstanceOf(HasMany::class);
    expect($relation->getRelated())->toBeInstanceOf(AssignedProductMovement::class);
});
