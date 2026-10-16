<?php

return [

    'likes' => [

        // Seconds a like count stays cached. Likes and unlikes that change
        // state invalidate the key; a concurrent reader can repopulate a
        // stale value, bounded by this TTL.
        'count_cache_ttl' => (int) env('LIKES_COUNT_CACHE_TTL', 600),
    ],

    'feed' => [

        // Seconds a feed page (ordered post IDs + cursor bookends) stays
        // cached per user. Follow/unfollow bumps a per-user version and
        // invalidates immediately; new posts from followed authors are
        // TTL-bounded.
        'page_cache_ttl' => (int) env('FEED_PAGE_CACHE_TTL', 30),
    ],
];
