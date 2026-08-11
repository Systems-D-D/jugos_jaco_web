<?php

use App\Models\AssignedProduct;
use App\Models\ProductReturn;
use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `reference_id` no tiene tipo: la venta #5 y la devolución #5 son hoy
     * indistinguibles entre los mismos asientos de inventario. Sin esto, la
     * reversión de una venta anulada (creada desde la web) podría compensar
     * el asiento equivocado. Ver docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §5.2.
     */
    public function up(): void
    {
        Schema::table('management_inventory', function (Blueprint $table) {
            $table->string('reference_type')->nullable()->after('reference_id');
        });

        // Backfill histórico: no hay ninguna otra señal fiable que la
        // descripción del asiento para clasificar reference_id retroactivamente
        // (reference_id nunca llevó reference_type). Los asientos "venta"
        // legacy con reference_id NULL (datos de una versión anterior del
        // código) quedan sin clasificar a propósito: no hay id que resolver.
        DB::table('management_inventory')
            ->whereNotNull('reference_id')
            ->where(function ($query) {
                $query->where('description', 'like', 'Asignación de producto%')
                    ->orWhere('description', 'like', 'Ajuste de producto asignado%')
                    ->orWhere('description', 'like', 'Eliminación de producto asignado%');
            })
            ->update(['reference_type' => AssignedProduct::class]);

        DB::table('management_inventory')
            ->whereNotNull('reference_id')
            ->where(function ($query) {
                $query->where('description', 'like', 'Devolución de producto%')
                    ->orWhere('description', 'like', 'Reversión de devolución%');
            })
            ->update(['reference_type' => ProductReturn::class]);

        DB::table('management_inventory')
            ->whereNotNull('reference_id')
            ->where('description', 'like', 'Venta de producto%')
            ->update(['reference_type' => Sale::class]);
    }

    public function down(): void
    {
        Schema::table('management_inventory', function (Blueprint $table) {
            $table->dropColumn('reference_type');
        });
    }
};
