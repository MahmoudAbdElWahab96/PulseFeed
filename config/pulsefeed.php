<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PulseFeed tunables
    |--------------------------------------------------------------------------
    */

    'likes' => [

        // Seconds a post's like count stays cached. The database remains the
        // source of truth; likes/unlikes that change state invalidate the
        // key immediately. A concurrent reader that recomputed a count just
        // before a writer's insert can repopulate a stale value - that
        // staleness window is bounded by this TTL.
        'count_cache_ttl' => (int) env('LIKES_COUNT_CACHE_TTL', 600),
    ],

    'feed' => [

        // Seconds a feed page (ordered post IDs + cursor bookends) stays
        // cached per user. New posts from already-followed authors are not
        // invalidated in this phase (no fan-out), so the maximum feed
        // staleness equals this TTL. Follow/unfollow bumps a per-user
        // version immediately, which does invalidate all pages.
        'page_cache_ttl' => (int) env('FEED_PAGE_CACHE_TTL', 30),
    ],
];
