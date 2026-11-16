<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El nombre de la cuenta ahora se autocompleta como "Negocio (Cliente)":
     * business_name (50) + first_name/last_name (50 + 50) + separadores no
     * caben en los 64 caracteres originales.
     */
    public function up(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->string('name', 160)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->string('name', 64)->nullable()->change();
        });
    }
};
