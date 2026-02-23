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
        Schema::create('assigned_product_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detail_assigned_product_id')->constrained('detail_assigned_products')->onDelete('cascade');
            $table->string('type'); // CHANGE or ROYALTY
            $table->decimal('quantity', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assigned_product_movements');
    }
};
