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
        // A completed piece of work shown on the public profile (spec §12).
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();

            // The catalogue service this job was, when the professional files it
            // under one. Nullable so a general "other work" album is possible.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Where the job was done, shown as "Plumbing · Nakuru" on the card.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->date('completed_on')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            // The public gallery reads published items in display order.
            $table->index(['professional_profile_id', 'is_published', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_items');
    }
};
