<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Set by endpoints that respond with the user's own freshly issued
     * profile (register/login), where no authenticated request user exists
     * yet to compare against.
     */
    public bool $own = false;

    public function markAsOwn(): static
    {
        $this->own = true;

        return $this;
    }

    /**
     * Email is only exposed for the authenticated user themselves; other
     * embeds (post authors, follow targets) never leak it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            $this->mergeWhen($this->own || $this->is($request->user()), [
                'email' => $this->email,
                'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
