<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('visited')->default(false);
            $table->date('visited_date');
            $table->timestamps();

            $table->index('client_id');
            $table->index('user_id');
            $table->index('visited_date');

            // Prevent duplicate visits for the same client on the same day by the same user
            $table->unique(['client_id', 'visited_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_visits');
    }
};
