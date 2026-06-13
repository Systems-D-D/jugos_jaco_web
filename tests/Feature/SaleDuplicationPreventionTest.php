<?php

use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\AccountReceivableService;
use App\Services\AssignedProductMovementService;
use App\Services\ClientVisitService;
use App\Services\ManagementInventoryService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $category = Category::factory()->create(['name' => 'Test ' . uniqid()]);
    $product = Product::factory()->create(['category_id' => $category->id]);
    $typePrice = TypePrice::factory()->create();
    $productPrice = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'type_price_id' => $typePrice->id,
    ]);
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ]);

    Auth::login($user);

    $this->branch = $branch;
    $this->employee = $employee;
    $this->user = $user;
    $this->client = $client;
    $this->product = $product;
    $this->typePrice = $typePrice;
    $this->productPrice = $productPrice;
    $this->detail = $detail;
});

// ✅ Idempotency — request secuencial con mismo UUID retorna venta existente
it('returns existing sale id when same client_request_uuid is sent twice', function () {
    $uuid = (string) \Illuminate\Support\Str::uuid();

    $existingSale = Sale::factory()->create([
        'employee_id' => $this->employee->id,
        'client_id' => $this->client->id,
        'client_request_uuid' => $uuid,
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/sales', [
            'client_request_uuid' => $uuid,
            'client_id' => $this->client->id,
            'employee_id' => $this->employee->id,
            'payment_term' => 'cash',
            'payment_method' => 'cash',
            'cash_amount' => 100,
            'products' => [[
                'product_id' => $this->product->id,
                'product_price_id' => $this->productPrice->id,
                'quantity' => 1,
            ]],
        ]);

    $response->assertStatus(200);
    expect($response->json('data'))->toBe($existingSale->id);
});

// ✅ Idempotency — no se inserta un segundo registro en la tabla sales
it('does not insert a duplicate row in sales table when uuid is reused', function () {
    $uuid = (string) \Illuminate\Support\Str::uuid();

    Sale::factory()->create([
        'employee_id' => $this->employee->id,
        'client_id' => $this->client->id,
        'client_request_uuid' => $uuid,
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/sales', [
            'client_request_uuid' => $uuid,
            'client_id' => $this->client->id,
            'employee_id' => $this->employee->id,
            'payment_term' => 'cash',
            'payment_method' => 'cash',
            'cash_amount' => 0,
            'products' => [[
                'product_id' => $this->product->id,
                'product_price_id' => $this->productPrice->id,
                'quantity' => 1,
            ]],
        ]);

    expect(Sale::count())->toBe(1);
});

// ⚠️ Edge case — sin UUID el sistema sigue funcionando (backward compatible)
it('creates a sale successfully when no client_request_uuid is provided', function () {
    $managementInventory = Mockery::mock(ManagementInventoryService::class);
    $accountReceivable = Mockery::mock(AccountReceivableService::class);
    $clientVisit = Mockery::mock(ClientVisitService::class);
    $clientVisit->shouldReceive('registerVisit')->once()->andReturn(new ClientVisit());
    $movementService = Mockery::mock(AssignedProductMovementService::class);

    $saleService = new SaleService(
        $managementInventory,
        $accountReceivable,
        $clientVisit,
        $movementService,
    );

    $saleData = [
        'client_id' => $this->client->id,
        'employee_id' => $this->employee->id,
        'sale_date' => now(),
        'cash_amount' => 500,
        'payment_reference' => null,
        'notes' => null,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => $this->branch->id,
        // Sin client_request_uuid — backward compatible
    ];

    $productsData = [[
        'origin' => 'api',
        'product_id' => $this->product->id,
        'name' => 'Test Product',
        'code' => 'TST-001',
        'type_price_id' => $this->typePrice->id,
        'unit_name' => 'Unidad',
        'unit_abbreviation' => 'U',
        'quantity' => 5,
        'unit_price_without_tax' => 100,
        'unit_tax_amount' => 0,
        'tax_category_id' => null,
        'tax_category_name' => 'Exento',
        'tax_rate' => 0,
        'price_include_tax' => false,
        'line_subtotal' => 500,
        'line_tax_amount' => 0,
        'line_total' => 500,
    ]];

    $sale = $saleService->createSale($saleData, $productsData);

    expect($sale->id)->toBeInt();
    expect(Sale::count())->toBe(1);
    expect($sale->client_request_uuid)->toBeNull();
});

// ✅ lockForUpdate — sale_quantity se actualiza exactamente una vez (no doble)
it('updates sale_quantity exactly once per call with lockForUpdate protection', function () {
    $managementInventory = Mockery::mock(ManagementInventoryService::class);
    $accountReceivable = Mockery::mock(AccountReceivableService::class);
    $clientVisit = Mockery::mock(ClientVisitService::class);
    $clientVisit->shouldReceive('registerVisit')->andReturn(new ClientVisit());
    $movementService = Mockery::mock(AssignedProductMovementService::class);

    $saleService = new SaleService(
        $managementInventory,
        $accountReceivable,
        $clientVisit,
        $movementService,
    );

    $saleData = [
        'client_id' => $this->client->id,
        'employee_id' => $this->employee->id,
        'sale_date' => now(),
        'cash_amount' => 1000,
        'payment_reference' => null,
        'notes' => null,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => $this->branch->id,
    ];

    $productsData = [[
        'origin' => 'api',
        'product_id' => $this->product->id,
        'name' => 'Test Product',
        'code' => 'TST-001',
        'type_price_id' => $this->typePrice->id,
        'unit_name' => 'Unidad',
        'unit_abbreviation' => 'U',
        'quantity' => 10,
        'unit_price_without_tax' => 100,
        'unit_tax_amount' => 0,
        'tax_category_id' => null,
        'tax_category_name' => 'Exento',
        'tax_rate' => 0,
        'price_include_tax' => false,
        'line_subtotal' => 1000,
        'line_tax_amount' => 0,
        'line_total' => 1000,
    ]];

    $saleService->createSale($saleData, $productsData);

    $this->detail->refresh();

    // sale_quantity debe ser exactamente 10 (no 20 por doble update)
    expect((int) $this->detail->sale_quantity)->toBe(10);
    // stock debe quedar en 40 (50 - 10)
    expect((int) $this->detail->stock)->toBe(40);
});

// ❌ DB constraint — UUID duplicado en BD lanza IntegrityConstraintViolation
it('throws a database unique constraint violation when inserting duplicate uuid directly', function () {
    $uuid = (string) \Illuminate\Support\Str::uuid();

    Sale::factory()->create([
        'client_request_uuid' => $uuid,
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    expect(fn () => Sale::factory()->create([
        'client_request_uuid' => $uuid,
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
