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
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();

            // One profile per account, so the unique constraint is on user_id
            // rather than a composite. Deleting the account removes the profile
            // with it — an orphaned profile has no one who can manage it.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('business_name');
            $table->text('about')->nullable();
            $table->unsignedTinyInteger('experience_years')->default(0);

            // A business contact number, kept separate from the account phone
            // so a tradesperson can list an office line without changing how
            // they sign in.
            $table->string('phone', 20)->nullable();

            // The professional's home base (spec §11). Their service areas are
            // a separate, wider set in `professional_locations`.
            $table->foreignId('base_location_id')->nullable()->constrained('locations')->nullOnDelete();

            /*
             * Denormalised aggregates.
             *
             * Rating and completed-job counts are read on every search result
             * card, so computing them per row would mean an aggregate subquery
             * across reviews and bookings for every listing. They are
             * recalculated when a review is created or a booking completes.
             */
            $table->string('verification_status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('completed_jobs_count')->default(0);

            $table->timestamps();

            // Search filters on exactly this pair: verified professionals, best
            // rated first.
            $table->index(['verification_status', 'rating_avg']);
            $table->index('base_location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_profiles');
    }
};
