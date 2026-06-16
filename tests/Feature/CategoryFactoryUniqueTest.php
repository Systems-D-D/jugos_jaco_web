<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates 300 products without category unique constraint violation', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    for ($i = 0; $i < 300; $i++) {
        Product::factory()->create(['name' => 'Product ' . $i, 'is_active' => true]);
    }

    $categoryCount = \App\Models\Category::count();
    expect($categoryCount)->toBeGreaterThanOrEqual(300);
});
