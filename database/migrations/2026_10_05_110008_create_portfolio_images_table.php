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
         * Photos for a portfolio item, split into their own table rather than a
         * JSON column on the item: each file needs its own path, alt text and
         * ordering, and a JSON blob cannot be constrained or reordered with a
         * single UPDATE.
         */
        Schema::create('portfolio_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();

            $table->string('path');
            $table->string('caption')->nullable();

            // Which photo is the gallery card's cover. The first image is the
            // default; the professional can promote another.
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['portfolio_item_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_images');
    }
};
