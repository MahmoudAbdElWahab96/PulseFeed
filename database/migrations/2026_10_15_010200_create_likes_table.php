<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->timestamps();

            // Duplicate likes are impossible, under any concurrency.
            $table->unique(['user_id', 'post_id'], 'likes_user_post_uniq');

            // Per-post counts and batched liked_by_me lookups.
            $table->index(['post_id', 'user_id'], 'likes_post_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};
