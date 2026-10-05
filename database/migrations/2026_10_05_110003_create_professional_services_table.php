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
        Schema::create('professional_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // What the professional says about this specific offering — the
            // catalogue's description is generic, this one is theirs.
            $table->text('description')->nullable();

            $table->string('pricing_type', 20)->default('quote');

            /*
             * Prices as integer minor units. A range rather than a single
             * figure because quotes in this market are typically "from KSh
             * 1,500", and the search filter (spec §6) narrows on the floor.
             *
             * Nullable rather than zero: a "quote on inspection" service has no
             * price, and storing 0 would render as "KSh 0".
             */
            $table->unsignedBigInteger('price_min_cents')->nullable();
            $table->unsignedBigInteger('price_max_cents')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A professional lists a given catalogue service once.
            $table->unique(['professional_profile_id', 'service_id']);

            // Backs the price-range filter in search.
            $table->index(['is_active', 'price_min_cents']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_services');
    }
};
