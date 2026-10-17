<?php

return [

    'likes' => [

        'count_cache_ttl' => (int) env('LIKES_COUNT_CACHE_TTL', 600),
    ],

    'feed' => [

        'page_cache_ttl' => (int) env('FEED_PAGE_CACHE_TTL', 30),
    ],

    'rate_limits' => [

        'reads_per_minute' => (int) env('RATE_LIMIT_READS', 120),

        'writes_per_minute' => (int) env('RATE_LIMIT_WRITES', 30),

        'register_per_minute' => (int) env('RATE_LIMIT_REGISTER', 10),
    ],
];
