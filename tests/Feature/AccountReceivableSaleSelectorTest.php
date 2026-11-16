<?php

use App\Filament\Resources\AccountReceivableResource;
use App\Models\Client;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creditSaleFor(array $clientAttributes, array $saleAttributes = []): Sale
{
    $client = Client::factory()->create($clientAttributes);

    return Sale::factory()->create(array_merge([
        'client_id' => $client->id,
        'payment_term' => 'credit',
        'total_amount' => 1250,
        'cash_amount' => 0,
    ], $saleAttributes));
}

it('shows the business name with the client name in parentheses', function () {
    $sale = creditSaleFor(['business_name' => 'Pulpería La Esperanza', 'first_name' => 'María', 'last_name' => 'López']);

    expect($sale->client_display_name)->toBe('Pulpería La Esperanza (María López)')
        ->and(AccountReceivableResource::saleOptionLabel($sale))
        ->toBe("#{$sale->id} - Pulpería La Esperanza (María López) L.1,250.00");
});

it('falls back to the client name when the business name is empty', function () {
    $sale = creditSaleFor(['business_name' => '', 'first_name' => 'María', 'last_name' => 'López']);

    expect($sale->client_display_name)->toBe('María López');
});

it('finds sales by business name, client name, invoice number or id', function () {
    $esperanza = creditSaleFor(['business_name' => 'Pulpería La Esperanza', 'first_name' => 'María', 'last_name' => 'López'], ['invoice_number' => 4521]);
    $sabor = creditSaleFor(['business_name' => 'Cafetería El Buen Sabor', 'first_name' => 'José', 'last_name' => 'Martínez']);

    expect(Sale::searchByClient('Esperanza')->pluck('id')->all())->toBe([$esperanza->id])
        ->and(Sale::searchByClient('José')->pluck('id')->all())->toBe([$sabor->id])
        ->and(Sale::searchByClient('María López')->pluck('id')->all())->toBe([$esperanza->id])
        ->and(Sale::searchByClient('María Martínez')->pluck('id')->all())->toBe([])
        ->and(Sale::searchByClient('4521')->pluck('id')->all())->toBe([$esperanza->id])
        ->and(Sale::searchByClient("#{$sabor->id}")->pluck('id')->all())->toBe([$sabor->id]);
});
