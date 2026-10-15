<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class FollowService
{
    /**
     * Follow a target user as the given follower. Returns true when a new
     * relationship was created, false when it already existed. insertOrIgnore
     * against the unique constraint keeps concurrent duplicate requests safe.
     *
     * @throws ValidationException when the target is the follower themselves
     */
    public function follow(User $follower, int $targetId): bool
    {
        if ($follower->id === $targetId) {
            throw ValidationException::withMessages([
                'user_id' => __('You cannot follow yourself.'),
            ]);
        }

        User::query()->findOrFail($targetId);

        return (bool) Follow::query()->insertOrIgnore([
            'follower_id' => $follower->id,
            'followee_id' => $targetId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Unfollow a target user. Returns true when a relationship was removed,
     * false when none existed (idempotent).
     *
     * @throws ValidationException when the target is the follower themselves
     */
    public function unfollow(User $follower, int $targetId): bool
    {
        if ($follower->id === $targetId) {
            throw ValidationException::withMessages([
                'user_id' => __('You cannot unfollow yourself.'),
            ]);
        }

        User::query()->findOrFail($targetId);

        return (bool) Follow::query()
            ->where('follower_id', $follower->id)
            ->where('followee_id', $targetId)
            ->delete();
    }
}
