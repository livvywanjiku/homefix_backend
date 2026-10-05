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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // The county or administrative region the town sits in. Shown
            // alongside the name ("Nakuru, Nakuru County") and used to group
            // locations in the picker.
            $table->string('region')->nullable();

            /*
             * Coordinates for distance search (spec §11). Nullable because a
             * location can be added before anyone geocodes it — a missing
             * coordinate excludes it from radius filtering, which is better
             * than placing it at 0,0 in the Gulf of Guinea.
             *
             * 7 decimal places is ~1cm of precision, well beyond what a
             * service-area radius needs, and decimal (not float) keeps the
             * value exact.
             */
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A town name is only unique within its region, so the pair is the
            // natural key rather than the name alone.
            $table->unique(['name', 'region']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
