<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una venta nunca se borra físicamente, se anula (ver
     * docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §2 y fase 0).
     * `SET NULL` era una trampa: si alguna vez se intentara un borrado físico
     * (manual, un script, una regresión futura), la cuenta por cobrar
     * sobreviviría huérfana con sus pagos, en silencio. `RESTRICT` lo
     * convierte en un error de base de datos explícito en vez de datos
     * inconsistentes.
     */
    public function up(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropForeign(['sales_id']);
            $table->foreign('sales_id')->references('id')->on('sales')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropForeign(['sales_id']);
            $table->foreign('sales_id')->references('id')->on('sales')->nullOnDelete();
        });
    }
};
