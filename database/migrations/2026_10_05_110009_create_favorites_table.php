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
        /*
         * A customer's saved professionals (spec §7).
         *
         * Polymorphic-free on purpose: the only thing a customer can favourite
         * is a professional profile, so a plain foreign key gives a real
         * constraint instead of morph columns that nothing else uses.
         */
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // Favouriting twice is a no-op, not a duplicate row.
            $table->unique(['user_id', 'professional_profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
