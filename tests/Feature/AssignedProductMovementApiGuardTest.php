<?php

use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regresión: el endpoint de movimientos no tenía idempotencia (un reintento del
 * móvil registraba la regalía dos veces y bajaba el sobrante) ni verificación de
 * pertenencia (cualquier usuario podía alterar el sobrante de otro vendedor).
 */

function assignedDetailFor(User $user, array $overrides = []): DetailAssignedProduct
{
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $user->employee_id,
        'date' => $overrides['date'] ?? now(),
    ]);

    return DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => $overrides['quantity'] ?? 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);
}

// --- Fix 4: idempotencia ---

it('registers the movement only once when the same client_request_uuid is retried', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user);
    $uuid = (string) Str::uuid();

    $payload = [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 6,
        'client_request_uuid' => $uuid,
    ];

    $this->postJson('/api/product-movements', $payload)->assertStatus(201);
    $this->postJson('/api/product-movements', $payload)->assertStatus(200);

    expect(AssignedProductMovement::count())->toBe(1);
    expect((float) $detail->fresh()->royalties_quantity)->toBe(6.0);
    expect((float) $detail->fresh()->stock)->toBe(44.0);
});

it('persists the client_request_uuid and returns the same movement on retry', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user);
    $uuid = (string) Str::uuid();

    $first = $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'change',
        'quantity' => 2,
        'client_request_uuid' => $uuid,
    ]);

    $second = $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'change',
        'quantity' => 2,
        'client_request_uuid' => $uuid,
    ]);

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(AssignedProductMovement::where('client_request_uuid', $uuid)->count())->toBe(1);
});

it('still registers separate movements when no uuid is sent', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user);

    $payload = [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 3,
    ];

    $this->postJson('/api/product-movements', $payload)->assertStatus(201);
    $this->postJson('/api/product-movements', $payload)->assertStatus(201);

    expect(AssignedProductMovement::count())->toBe(2);
    expect((float) $detail->fresh()->royalties_quantity)->toBe(6.0);
});

it('rejects a malformed client_request_uuid', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user);

    $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 1,
        'client_request_uuid' => 'no-es-un-uuid',
    ])->assertJsonValidationErrors(['client_request_uuid']);
});

// --- Fix 4: errores de negocio no son 500 ---

it('returns 422 instead of 500 when there is not enough stock', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user, ['quantity' => 5]);

    $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 10,
    ])->assertStatus(422);

    expect(AssignedProductMovement::count())->toBe(0);
    expect((float) $detail->fresh()->royalties_quantity)->toBe(0.0);
});

// --- Fix 5: pertenencia ---

it('forbids creating a movement on another employee assignment', function () {
    $owner = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $intruder = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);

    $detail = assignedDetailFor($owner);

    $this->actingAs($intruder, 'sanctum');

    $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 5,
    ])->assertStatus(403);

    expect(AssignedProductMovement::count())->toBe(0);
    expect((float) $detail->fresh()->royalties_quantity)->toBe(0.0);
});

it('forbids creating a movement on an assignment from another date', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user, ['date' => now()->subDay()]);

    $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 5,
    ])->assertStatus(403);

    expect(AssignedProductMovement::count())->toBe(0);
});

it('forbids deleting a movement that belongs to another employee', function () {
    $owner = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $intruder = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);

    $detail = assignedDetailFor($owner);

    $this->actingAs($owner, 'sanctum');
    $created = $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 5,
    ])->assertStatus(201);

    $movementId = $created->json('data.id');

    $this->actingAs($intruder, 'sanctum');
    $this->deleteJson("/api/product-movements/{$movementId}")->assertStatus(403);

    expect(AssignedProductMovement::find($movementId))->not->toBeNull();
    expect((float) $detail->fresh()->royalties_quantity)->toBe(5.0);
});

it('returns 404 when deleting a movement that does not exist', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $this->deleteJson('/api/product-movements/99999')->assertStatus(404);
});

it('allows the owner to delete their own movement and reverts the accumulator', function () {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($user, 'sanctum');

    $detail = assignedDetailFor($user);

    $movementId = $this->postJson('/api/product-movements', [
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 5,
    ])->json('data.id');

    $this->deleteJson("/api/product-movements/{$movementId}")->assertStatus(200);

    expect(AssignedProductMovement::find($movementId))->toBeNull();
    expect((float) $detail->fresh()->royalties_quantity)->toBe(0.0);
    expect((float) $detail->fresh()->stock)->toBe(50.0);
});
