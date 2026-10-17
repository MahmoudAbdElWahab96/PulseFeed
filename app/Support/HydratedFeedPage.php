<?php

namespace App\Support;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;

class HydratedFeedPage extends CursorPaginator
{
    public function __construct(
        $items,
        int $perPage,
        private readonly ?string $cachedNextCursor,
        private readonly ?string $cachedPreviousCursor,
    ) {
        parent::__construct(
            $items,
            $perPage,
            null,
            ['path' => Paginator::resolveCurrentPath(), 'cursorName' => 'cursor'],
        );
    }

    public function nextCursor(): ?Cursor
    {
        return $this->cachedNextCursor === null
            ? null
            : Cursor::fromEncoded($this->cachedNextCursor);
    }

    public function previousCursor(): ?Cursor
    {
        return $this->cachedPreviousCursor === null
            ? null
            : Cursor::fromEncoded($this->cachedPreviousCursor);
    }
}
