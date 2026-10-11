<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos a customer attaches to a request (spec §8).
     *
     * A leaking pipe or a cracked wall is far easier to show than to describe,
     * and these photos are what let a professional quote without a site visit —
     * which is the whole point of the request board.
     */
    public function up(): void
    {
        Schema::create('service_request_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();

            /*
             * Stored on the `public` disk with a random filename, matching how
             * verification documents and portfolio images are handled. The
             * generated name is what keeps a photo of someone's kitchen from
             * being guessable; the path is never taken from the request.
             */
            $table->string('path');

            // Kept for the download filename and as a fallback alt text.
            $table->string('original_name')->nullable();

            // Bytes, so the UI can say "2.4 MB" without a HEAD request.
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_request_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_images');
    }
};
