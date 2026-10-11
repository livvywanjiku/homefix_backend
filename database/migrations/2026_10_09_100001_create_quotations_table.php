<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A professional's priced offer on a customer's job posting (spec §9).
     *
     * One request attracts several of these; exactly one can be accepted, and
     * accepting it is what turns a request into a booking.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();

            // The handle the customer reads out when discussing the price.
            $table->string('reference', 20)->unique();

            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            /*
             * The quoting account, not its profile row. A quotation is an offer
             * made by a person who is answerable for it, and both parties to this
             * table (customer, professional) are accounts — the same shape
             * `service_requests.customer_id` already uses.
             */
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('pending');

            // The covering note: what they will do, and any assumption the price
            // rests on ("assuming the wall is not already damp").
            $table->text('message')->nullable();

            // Working days to finish, and the earliest date they could start.
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->date('available_from')->nullable();

            // How long the price stands. Issued by the platform when unstated.
            $table->timestamp('valid_until')->nullable();

            /*
             * The itemised price, in minor units.
             *
             * These five columns are a cache of what `quotation_items` sums to,
             * recalculated on every line write — see Quotation::recalculateTotals().
             * They exist so the comparison table and a professional's earnings
             * list read without joining and grouping the lines each time. They
             * are never taken from a request payload.
             */
            $table->unsignedBigInteger('labour_cents')->default(0);
            $table->unsignedBigInteger('materials_cents')->default(0);
            $table->unsignedBigInteger('transport_cents')->default(0);
            $table->unsignedBigInteger('other_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->timestamps();

            /*
             * One price per professional per job.
             *
             * This is a structural guarantee rather than a rule the controller
             * has to remember: without it a professional could undercut
             * themselves with a second, lower offer, and the customer's
             * comparison table would have no way to tell which figure was the
             * live one.
             */
            $table->unique(['service_request_id', 'professional_id']);

            // The customer's comparison table, and the professional's own list.
            $table->index(['service_request_id', 'status']);
            $table->index(['professional_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
