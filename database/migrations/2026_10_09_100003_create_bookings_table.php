<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agreed piece of work (spec §10).
     *
     * A booking is the point at which a price stops being a suggestion. It is
     * created by accepting a quotation — which is the only way it is created
     * today — and thereafter the two sides advance it through the states in
     * BookingStatus.
     *
     * The terms are copied onto the row rather than read back through the
     * quotation. A booking is a contract: re-pricing the quotation it came from
     * must not silently rewrite what the customer agreed to pay.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // The handle both sides quote when they call about the job.
            $table->string('reference', 20)->unique();

            /*
             * Both nullable with nullOnDelete: a booking outlives the
             * advertisement that produced it, and deleting a customer's request
             * or a professional's offer must not delete the record of work that
             * was agreed and carried out.
             */
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('pending');

            // The agreed scope, frozen at acceptance. The customer's original
            // brief stays on the request; this is what was actually priced.
            $table->string('title');
            $table->text('description')->nullable();

            // Where to turn up. Copied from the request, and released to the
            // professional by the booking existing rather than by a policy check
            // on the request.
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->string('scheduled_time_slot', 20)->nullable();

            /*
             * The agreed price, in minor units, copied from the accepted
             * quotation. Never fillable — see the class note on Booking.
             */
            $table->unsignedBigInteger('labour_cents')->default(0);
            $table->unsignedBigInteger('materials_cents')->default(0);
            $table->unsignedBigInteger('transport_cents')->default(0);
            $table->unsignedBigInteger('other_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);

            // When the customer accepted. Distinct from `created_at` because a
            // booking may later be created ahead of acceptance.
            $table->timestamp('agreed_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            // Who called it off, so a cancellation can be attributed rather than
            // merely recorded.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // Both dashboards list a party's own bookings, newest first.
            $table->index(['customer_id', 'status']);
            $table->index(['professional_id', 'status']);

            // The diary: what is coming up for this professional.
            $table->index(['professional_id', 'scheduled_for']);

            // An administrator filtering by state across the platform.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
