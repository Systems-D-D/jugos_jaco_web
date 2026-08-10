<?php

use App\Models\AssignedProduct;
use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esquema necesario para anular ventas (ver
     * docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §10):
     * auditoría de la anulación, canal de origen (para reportería; la
     * reversión se guía por evidencia, no por este campo) y dos columnas que
     * el código ya envía a Sale::create() pero que Eloquent descarta en
     * silencio por no existir (branch_id, payment_reference).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('employee_id')
                ->constrained('branches')->restrictOnDelete();
            $table->string('payment_reference')->nullable()->after('cash_amount');
            $table->enum('channel', ['app', 'web'])->nullable()->after('client_request_uuid');
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });

        $this->backfillBranchId();
        $this->backfillChannel();
    }

    /**
     * branch_id se resuelve desde employees.branch_id: es la mejor
     * aproximación disponible, aunque el empleado pudo haber cambiado de
     * sucursal después de la venta.
     *
     * Se recorre por empleado (no UPDATE...JOIN) porque esa sintaxis no es
     * portable entre MySQL y SQLite, y la suite de tests corre sobre SQLite.
     */
    private function backfillBranchId(): void
    {
        $employees = DB::table('employees')
            ->select('id', 'branch_id')
            ->whereNotNull('branch_id')
            ->get();

        foreach ($employees as $employee) {
            DB::table('sales')
                ->where('employee_id', $employee->id)
                ->whereNull('branch_id')
                ->update(['branch_id' => $employee->branch_id]);
        }
    }

    /**
     * No existe ninguna columna histórica que indique el canal. Se infiere
     * por evidencia: si hay una AssignedProduct para (employee_id, sale_date)
     * de la venta, se asume 'app' (el flujo de venta desde la app siempre
     * pasa por una asignación); el resto se marca 'web'. Es una aproximación
     * para reportería, no para la lógica de reversión (que siempre debe
     * guiarse por evidencia directa, no por este campo).
     */
    private function backfillChannel(): void
    {
        $assignedProductDates = AssignedProduct::select('employee_id', 'date')
            ->get()
            ->map(fn ($row) => $row->employee_id . '|' . $row->date->toDateString())
            ->flip();

        Sale::query()->select('id', 'employee_id', 'sale_date')
            ->chunkById(200, function ($sales) use ($assignedProductDates) {
                foreach ($sales as $sale) {
                    $key = $sale->employee_id . '|' . $sale->sale_date->toDateString();
                    $channel = $assignedProductDates->has($key) ? 'app' : 'web';

                    Sale::whereKey($sale->id)->update(['channel' => $channel]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'channel', 'payment_reference']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
