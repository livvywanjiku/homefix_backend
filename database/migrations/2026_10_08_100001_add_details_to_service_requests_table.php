<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The three request fields the original table was missing (spec §8).
     *
     * Added rather than folded into `create_service_requests_table` because
     * that migration has already run wherever the app is deployed; editing it
     * in place would leave existing databases silently missing the columns.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            /*
             * A map pin for the job, when the customer drops one. Optional and
             * separate from `location_id`: the town is what search and matching
             * work on, the pin is what the professional needs to actually find
             * the address. A customer who only picks a town from the list still
             * has a perfectly good request.
             *
             * Same precision and type as `locations`, so the two can be
             * compared without a cast.
             */
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            /*
             * Access notes, gate codes, parking, "the dog is friendly" — the
             * things that do not belong in a description of the work.
             *
             * Shared with a professional only once they have quoted, alongside
             * the address: it is not part of the public listing.
             */
            $table->text('instructions')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'instructions']);
        });
    }
};
