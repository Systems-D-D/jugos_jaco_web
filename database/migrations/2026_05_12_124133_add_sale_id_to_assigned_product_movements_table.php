<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('detail_assigned_product_id')
                  ->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
