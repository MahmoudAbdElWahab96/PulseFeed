<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;

/**
 * A feed page replayed from cache: models are rehydrated from the cached
 * ordered post IDs, while the cursor bookends are the exact strings the
 * original page produced. Overriding the cursor accessors (instead of
 * re-deriving them from hydrated items) keeps meta.next_cursor,
 * meta.prev_cursor, and the page URLs byte-identical to a fresh page.
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends CursorPaginator<TKey, TValue>
 *
 * @implements CursorPaginatorContract<TKey, TValue>
 */
class HydratedFeedPage extends CursorPaginator
{
    /**
     * @param  iterable<TKey, TValue>  $items
     */
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
