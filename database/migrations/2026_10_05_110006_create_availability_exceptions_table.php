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
         * Dates the professional is unavailable despite their weekly pattern —
         * public holidays, leave, a fully booked day (spec §18).
         *
         * A date rather than a datetime range: the whole day is blocked, which
         * is how a tradesperson thinks about it ("I'm off on the 12th"). Partial
         * days are expressed by narrowing the weekly row instead.
         */
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();

            $table->date('blocked_on');
            $table->string('reason', 160)->nullable();

            $table->timestamps();

            // One row per blocked date; upserting means re-blocking a day
            // updates the reason instead of stacking duplicates.
            $table->unique(['professional_profile_id', 'blocked_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_exceptions');
    }
};
