<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del conteo de sobrantes:
 * - counted_by: usuario que registró la cantidad (cajero o administrador).
 * - detail_assigned_product_id: detalle de la asignación cuyo returned_quantity
 *   ajustó la devolución. Permite revertir ese ajuste al borrarla.
 * Ambas son nullables: las devoluciones anteriores no tienen estos datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_returns', function (Blueprint $table) {
            $table->foreignId('counted_by')
                ->nullable()
                ->after('employee_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('detail_assigned_product_id')
                ->nullable()
                ->after('counted_by')
                ->constrained('detail_assigned_products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('detail_assigned_product_id');
            $table->dropConstrainedForeignId('counted_by');
        });
    }
};
