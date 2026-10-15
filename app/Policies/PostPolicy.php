<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Only the post's owner may delete it.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->is($post->author);
    }
}
