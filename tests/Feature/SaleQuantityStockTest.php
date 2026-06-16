<?php

use App\Models\AssignedProduct;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\AccountReceivableService;
use App\Services\AssignedProductMovementService;
use App\Services\ClientVisitService;
use App\Services\ManagementInventoryService;
use App\Services\SaleService;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $branch = Branch::factory()->create();

    $employee = Employee::factory()->create(['branch_id' => $branch->id]);

    $user = User::factory()->create(['employee_id' => $employee->id]);
    Auth::login($user);

    $client = Client::factory()->create();

    $category = Category::factory()->create(['name' => 'Test Cat ' . uniqid()]);
    $product = Product::factory()->create(['category_id' => $category->id]);

    $typePrice = TypePrice::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);

    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 45,
        'sale_quantity' => 0,
        'changes_quantity' => 2,
        'royalties_quantity' => 1,
        'returned_quantity' => 0,
    ]);

    $this->branch = $branch;
    $this->employee = $employee;
    $this->user = $user;
    $this->client = $client;
    $this->product = $product;
    $this->typePrice = $typePrice;
    $this->detail = $detail;
});

it('does not double-subtract changes and royalties from stock when a sale is created', function () {
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
        'cash_amount' => 2800,
        'payment_reference' => null,
        'notes' => null,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => $this->branch->id,
    ];

    $productsData = [[
        'origin' => 'api',
        'product_id' => $this->product->id,
        'name' => $this->product->name ?? 'Test Product',
        'code' => $this->product->code ?? 'TST-001',
        'type_price_id' => $this->typePrice->id,
        'unit_name' => 'Unidad',
        'unit_abbreviation' => 'U',
        'quantity' => 28,
        'unit_price_without_tax' => 100,
        'unit_tax_amount' => 0,
        'tax_category_id' => null,
        'tax_category_name' => 'Exento',
        'tax_rate' => 0,
        'price_include_tax' => false,
        'line_subtotal' => 2800,
        'line_tax_amount' => 0,
        'line_total' => 2800,
    ]];

    $saleService->createSale($saleData, $productsData);

    $this->detail->refresh();

    // Quantity=45, Sales=28, Changes=2, Royalties=1 → Stock should be 14
    // Bug produces sale_quantity=31 (0+2+1+28), stock=45-31-2-1=11
    expect((int) $this->detail->stock)->toBe(14);
});
