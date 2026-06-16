<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detail_assigned_products', function (Blueprint $table) {
            $table->unique(['assigned_products_id', 'product_id'], 'detail_assigned_products_assigned_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('detail_assigned_products', function (Blueprint $table) {
            $table->dropUnique('detail_assigned_products_assigned_product_unique');
        });
    }
};
