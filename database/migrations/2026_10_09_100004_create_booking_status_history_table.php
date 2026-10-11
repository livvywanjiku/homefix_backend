<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every move a booking has made (spec §10).
     *
     * A booking's `status` column says where it is; this says how it got there.
     * Both sides of a job need the second one when a dispute is being arbitrated,
     * and reconstructing it from timestamps on the booking row would lose the
     * order of events, the actor and the reason.
     *
     * `from_status` is nullable because a booking's first row is the state it was
     * created in, which nothing preceded.
     */
    public function up(): void
    {
        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            /*
             * Nullable and nullOnDelete: a scheduled change has no actor, and an
             * administrator leaving the company must not erase the record of the
             * decisions they made.
             */
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            // Why, in the actor's own words — "customer rescheduled", "no access
            // on arrival". Optional because most moves need no explanation.
            $table->text('note')->nullable();

            $table->timestamps();

            // The timeline reads one booking's history in order.
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
    }
};
