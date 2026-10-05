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
        Schema::create('professional_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // The service areas a professional covers (spec §11). Unique so the
            // same town cannot be listed twice, which would double-count in a
            // "covers 4 areas" display.
            $table->unique(['professional_profile_id', 'location_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_locations');
    }
};
