<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The administration audit trail.
 *
 * Every mutation made from the admin area writes one row here. The trail is
 * append-only by convention rather than by database rule — nothing in the
 * application updates or deletes from it — and it deliberately survives the
 * account it names, so `user_id` is nullable and nulls on delete: an
 * administrator being removed must not erase the record of what they did.
 *
 * `subject_type` / `subject_id` are a loose pointer rather than a foreign key.
 * The trail has to be able to name an entity that has since been deleted, which
 * a cascading constraint would prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // A machine name from App\Enums\AdminAction, so the trail can be
            // filtered and grouped without matching on prose.
            $table->string('action', 60);

            $table->text('description')->nullable();

            // What was acted upon, when there was a single subject.
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Whatever else is worth keeping: the before and after of a
            // setting, the decision on a review, the reason for a suspension.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
