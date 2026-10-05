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
        Schema::create('professional_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('path');

            // Documents are reviewed individually as well as in aggregate, so
            // each carries its own outcome and the reviewer's reasoning.
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['professional_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_documents');
    }
};
