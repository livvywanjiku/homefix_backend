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
        // A customer's job posting (spec §8). One request may attract many
        // quotations, exactly one of which can become a booking.
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();

            // The reference a customer quotes on the phone: "REQ-10007".
            $table->string('reference', 20)->unique();

            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            /*
             * Nullable, and nullOnDelete rather than cascade: a request is the
             * customer's record of what they asked for, and retiring a service
             * from the catalogue should not delete their history with it.
             */
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('description');

            // The street address is shared with a professional only once they
            // are invited or have quoted; it is not in the public listing.
            $table->string('address')->nullable();

            $table->date('preferred_date')->nullable();

            // "morning" / "afternoon" / "evening" — a window, because a
            // customer cannot commit a tradesperson to an exact hour in advance.
            $table->string('preferred_time_slot', 20)->nullable();

            /*
             * The customer's budget range, in minor units. Nullable at both
             * ends: many customers have no idea what a job costs, and forcing a
             * figure would produce noise rather than signal.
             */
            $table->unsignedBigInteger('budget_min_cents')->nullable();
            $table->unsignedBigInteger('budget_max_cents')->nullable();

            $table->string('urgency', 20)->default('flexible');
            $table->string('status', 20)->default('open');

            /*
             * Denormalised quotation count.
             *
             * The customer's list shows "3 quotes" on every row, so counting
             * them per row would be a correlated subquery across the whole list.
             * Incremented when a quotation is submitted.
             */
            $table->unsignedInteger('quotes_count')->default(0);

            // Requests do not stay open indefinitely; a stale listing annoys
            // professionals who quote into a void.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // The public request board reads open requests, newest first.
            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
            $table->index('service_id');
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
