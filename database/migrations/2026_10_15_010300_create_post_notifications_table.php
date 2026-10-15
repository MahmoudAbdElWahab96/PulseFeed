<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Fan-out jobs write with insertOrIgnore; this constraint makes
            // job retries side-effect-free (no duplicate notifications).
            $table->unique(['post_id', 'follower_id'], 'post_notifications_post_follower_uniq');

            // A follower's notification inbox.
            $table->index('follower_id', 'post_notifications_follower_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_notifications');
    }
};
