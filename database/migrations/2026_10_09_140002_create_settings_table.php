<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform settings, as a key/value store.
 *
 * A table rather than a config file because these are edited by administrators
 * at runtime, and a table rather than one column per setting because the set
 * will keep growing and every addition would otherwise be a migration.
 *
 * `is_public` marks the handful of values the public site needs (the platform
 * name, the support address). Everything else is administrative and is never
 * served to an unauthenticated caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            $table->string('key', 100)->unique();

            // Stored as text whatever the type; `type` is what casts it back.
            // A nullable column is how "not set" is distinguished from "".
            $table->text('value')->nullable();

            // string | integer | boolean | json — see Setting::value().
            $table->string('type', 20)->default('string');

            // Groups the settings screen into panels.
            $table->string('group', 40)->default('general');

            $table->boolean('is_public')->default(false);

            $table->string('label', 150);
            $table->string('description', 255)->nullable();

            $table->timestamps();

            $table->index(['group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
