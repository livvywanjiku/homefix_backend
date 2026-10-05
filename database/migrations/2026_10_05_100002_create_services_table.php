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
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // Deleting a category removes its services: a service with no
            // category cannot be browsed or filtered to, so orphaning it would
            // only create rows nothing can reach.
            $table->foreignId('service_category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['service_category_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
