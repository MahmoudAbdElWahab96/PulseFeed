<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Duplicate follows are impossible at the storage layer; also the
            // driving index for the feed join on follower_id.
            $table->unique(['follower_id', 'followee_id'], 'follows_follower_followee_uniq');

            // Enumerates a user's followers for notification fan-out.
            $table->index(['followee_id', 'follower_id'], 'follows_followee_follower_idx');
        });

        // Self-follow is rejected by the database itself, in addition to
        // application-level validation. MySQL enforces CHECK from 8.0.16+.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE follows ADD CONSTRAINT chk_follows_no_self_follow CHECK (follower_id <> followee_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
