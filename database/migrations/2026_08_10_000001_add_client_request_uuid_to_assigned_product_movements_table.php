<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clave de idempotencia para los movimientos creados desde la app móvil:
     * un reintento por pérdida de respuesta no debe registrar la regalía/cambio
     * dos veces y bajar el sobrante del vendedor.
     *
     * Mismo patrón que sales.client_request_uuid.
     */
    public function up(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->char('client_request_uuid', 36)
                ->nullable()
                ->unique()
                ->after('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->dropUnique(['client_request_uuid']);
            $table->dropColumn('client_request_uuid');
        });
    }
};
