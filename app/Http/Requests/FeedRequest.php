<?php

namespace App\Http\Requests;

use App\Services\FeedService;
use Illuminate\Foundation\Http\FormRequest;

class FeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.FeedService::MAX_LIMIT],
            'cursor' => ['nullable', 'string'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? FeedService::DEFAULT_LIMIT);
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }
}
