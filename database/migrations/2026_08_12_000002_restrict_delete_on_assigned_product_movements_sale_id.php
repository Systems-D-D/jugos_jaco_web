<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mismo criterio que account_receivables.sales_id (ver la migración
     * hermana de esta): una venta nunca se borra físicamente, se anula. Con
     * `SET NULL`, borrar una venta dejaba la regalía/cambio sobreviviendo
     * huérfana y su acumulador (royalties_quantity/changes_quantity) sin
     * revertir, en silencio — exactamente el bug que motivó
     * docs/devflow/specs/2026-08-10-sale-deletion-analysis.md. `RESTRICT`
     * lo convierte en un error de base de datos explícito.
     */
    public function up(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
        });
    }
};
