<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales_reconciliations', function (Blueprint $table) {
            $table->decimal('product_shortage_total', 10, 2)->default(0)->after('notes');
            $table->foreignId('type_price_id')->nullable()->after('product_shortage_total')
                  ->constrained('types_prices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales_reconciliations', function (Blueprint $table) {
            $table->dropForeign(['type_price_id']);
            $table->dropColumn(['product_shortage_total', 'type_price_id']);
        });
    }
};
