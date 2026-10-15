<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('content', 1000);
            $table->timestamps();

            // Serves the feed join (follower -> authors -> newest posts) and
            // doubles as the index backing the user_id foreign key.
            $table->index(['user_id', 'created_at', 'id'], 'posts_user_created_id_idx');

            // Serves the deterministic (created_at DESC, id DESC) cursor order
            // when the optimizer prefers a single scan over merged author ranges.
            $table->index(['created_at', 'id'], 'posts_created_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
