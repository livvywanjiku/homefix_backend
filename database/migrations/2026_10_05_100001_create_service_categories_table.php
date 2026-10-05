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
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // Slugs are the public identifier used in URLs (/services/plumbing),
            // so they are globally unique and set once rather than derived on
            // every read.
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // A short emoji or icon key for the category grid. Kept as a plain
            // string so the frontend can decide how to render it.
            $table->string('icon', 32)->nullable();

            // Drives the order of the grid on /services; equal values fall back
            // to name so the listing is never non-deterministic.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
