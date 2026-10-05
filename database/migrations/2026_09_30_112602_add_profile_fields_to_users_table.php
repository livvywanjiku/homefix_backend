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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->string('avatar_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn([
                'phone',
                'status',
                'suspended_at',
                'suspension_reason',
                'avatar_path',
            ]);
        });
    }
};
