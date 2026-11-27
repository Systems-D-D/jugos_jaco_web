<?php

use App\Enums\ProductReturnTypeEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ManagementInventory;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\ProductReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id]);
    Auth::login(User::factory()->create(['employee_id' => $this->employee->id]));
    $this->product = Product::factory()->create(['name' => 'Jugo de Naranja 500ml', 'is_active' => true]);
    $this->inventory = FinishedProductInventory::create([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
        'stock' => 100,
        'min_stock' => 10,
    ]);
    $this->service = new ProductReturnService();
});

function prsqReturn(object $t, float $quantity, ProductReturnTypeEnum $type = ProductReturnTypeEnum::RETURNED): ProductReturn
{
    return ProductReturn::create([
        'product_id' => $t->product->id,
        'employee_id' => $t->employee->id,
        'quantity' => $quantity,
        'type' => $type,
        'reason' => 'Conteo de sobrantes',
        'affects_inventory' => true,
    ]);
}

function prsqLastMovement(object $t): ManagementInventory
{
    return ManagementInventory::where('model_type', FinishedProductInventory::class)
        ->where('model_id', $t->inventory->id)
        ->latest('id')
        ->firstOrFail();
}

// ✅ Happy path (regresión): devolución positiva → ENTRADA
it('registers a positive returned quantity as an ENTRADA', function () {
    $this->service->registerInventoryMovement(prsqReturn($this, 5));

    expect((float) $this->inventory->fresh()->stock)->toBe(105.0)
        ->and(prsqLastMovement($this)->type)->toBe(TypeInventoryManagementEnum::ENTRADA->value);
});

// ✅ Happy path: corrección negativa → SALIDA por el valor absoluto
it('registers a negative returned quantity as a SALIDA of its absolute value', function () {
    $this->service->registerInventoryMovement(prsqReturn($this, -2));

    $movement = prsqLastMovement($this);

    expect((float) $this->inventory->fresh()->stock)->toBe(98.0)
        ->and($movement->type)->toBe(TypeInventoryManagementEnum::SALIDA->value)
        ->and((float) $movement->quantity)->toBe(2.0);
});

// ✅ Reversión de una corrección negativa → ENTRADA
it('reverses a negative correction with an ENTRADA', function () {
    $return = prsqReturn($this, -2);
    $this->service->registerInventoryMovement($return);

    $this->service->reverseInventoryMovement($return, strict: true);

    expect((float) $this->inventory->fresh()->stock)->toBe(100.0)
        ->and(prsqLastMovement($this)->type)->toBe(TypeInventoryManagementEnum::ENTRADA->value);
});

// ✅ Regresión: reversión de una devolución positiva → SALIDA
it('still reverses a positive return with a SALIDA', function () {
    $return = prsqReturn($this, 5);
    $this->service->registerInventoryMovement($return);

    $this->service->reverseInventoryMovement($return);

    expect((float) $this->inventory->fresh()->stock)->toBe(100.0)
        ->and(prsqLastMovement($this)->type)->toBe(TypeInventoryManagementEnum::SALIDA->value);
});

// ⚠️ Edge: modo no estricto conserva el comportamiento histórico (no lanza)
it('swallows reversal errors when not strict', function () {
    $return = prsqReturn($this, 5);
    $this->service->registerInventoryMovement($return);
    $this->inventory->update(['stock' => 0]);

    $this->service->reverseInventoryMovement($return);

    expect((float) $this->inventory->fresh()->stock)->toBe(0.0);
});

// ❌ Failure: modo estricto propaga el error de inventario
it('rethrows reversal errors when strict', function () {
    $return = prsqReturn($this, 5);
    $this->service->registerInventoryMovement($return);
    $this->inventory->update(['stock' => 0]);

    $this->service->reverseInventoryMovement($return, strict: true);
})->throws(\RuntimeException::class);

// ⚠️ Edge: sin inventario en la sucursal, el modo estricto omite (como al crear)
it('skips a strict reversal when the branch has no inventory for the product', function () {
    $return = prsqReturn($this, 5);
    $this->inventory->delete();

    $this->service->reverseInventoryMovement($return, strict: true);

    expect(ManagementInventory::count())->toBe(0);
});
