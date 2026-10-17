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

            $table->unique(['user_id', 'post_id'], 'likes_user_post_uniq');

            $table->index(['post_id', 'user_id'], 'likes_post_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};
