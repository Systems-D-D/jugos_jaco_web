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
        Schema::table('detail_assigned_products', function (Blueprint $table) {
            $table->decimal('changes_quantity', 12, 2)->default(0)->after('returned_quantity');
            $table->decimal('royalties_quantity', 12, 2)->default(0)->after('changes_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detail_assigned_products', function (Blueprint $table) {
            $table->dropColumn(['changes_quantity', 'royalties_quantity']);
        });
    }
};
