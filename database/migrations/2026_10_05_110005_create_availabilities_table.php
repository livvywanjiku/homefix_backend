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
         * The weekly working pattern (spec §18).
         *
         * One row per day the professional works, rather than seven rows with
         * an `is_available` flag: "unavailable on Sunday" is the absence of a
         * row, so there is no way for a flag and a time range to disagree.
         */
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday through 6 = Saturday, matching Carbon's dayOfWeek, so
            // the value can be compared to a date without translation.
            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            $table->unique(['professional_profile_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
