# PulseFeed — News Feed API

A Twitter/Instagram-style feed API built with Laravel 13, MySQL 8.4 and Redis.
Users register, follow each other, publish posts, like posts, and read a
personalized, cursor-paginated feed. New posts notify followers through a
queued, chunked fan-out.

## Contents

- [Setup](#setup)
- [API](#api)
- [Architecture](#architecture)
- [Database & indexes](#database--indexes)
- [Feed](#feed)
- [Caching](#caching)
- [Queue & notifications](#queue--notifications)
- [Rate limiting](#rate-limiting)
- [Scaling to 5M users / 500k posts per day](#scaling-to-5m-users--500k-posts-per-day)
- [Testing](#testing)
- [Benchmark data](#benchmark-data)

## Setup

### Docker (recommended)

```bash
git clone https://github.com/MahmoudAbdElWahab96/PulseFeed.git && cd PulseFeed
docker compose up -d --build
```

That's all — no `.env` needed. The stack runs five services: `nginx` (port 8080),
`app` (PHP-FPM, runs migrations on start), `queue` (queue worker), `mysql` and
`redis`. The API is at `http://localhost:8080/api` and interactive OpenAPI docs at
`http://localhost:8080/docs/api`.

Run the test suite inside the container (it uses a separate `pulsefeed_testing`
database created automatically on first start):

```bash
docker compose exec app php vendor/bin/pest
```

Compose ships a local-only fallback `APP_KEY`; set `APP_KEY` in the environment for
anything beyond local use. Host ports can be changed with `APP_PORT`,
`FORWARD_DB_PORT` and `FORWARD_REDIS_PORT`.

### Native

Requires PHP 8.3+ (`pdo_mysql`, `redis`), MySQL 8.0.16+ and Redis.

```bash
composer install
cp .env.example .env
php artisan key:generate

mysql -u root -e "CREATE DATABASE pulsefeed; CREATE DATABASE pulsefeed_testing;"
php artisan migrate

php artisan serve              # http://127.0.0.1:8000
php artisan queue:work redis   # second terminal
```

Tests: `vendor/bin/pest`. They default to `root` with no password on
`127.0.0.1:3306`; override with e.g. `DB_USERNAME=app DB_PASSWORD=secret vendor/bin/pest`.

### Configuration

| Variable | Default | Purpose |
|---|---|---|
| `FEED_PAGE_CACHE_TTL` | 30 | Seconds a feed page stays cached |
| `LIKES_COUNT_CACHE_TTL` | 600 | Seconds a like count stays cached |
| `RATE_LIMIT_READS` | 120 | Authenticated reads per user per minute |
| `RATE_LIMIT_WRITES` | 30 | Writes per user per minute |
| `RATE_LIMIT_REGISTER` | 10 | Registrations per IP per minute |

## API

All endpoints are under `/api`, accept and return JSON, and (except register/login)
require `Authorization: Bearer <token>`.

| Method | Endpoint | Success | Errors |
|---|---|---|---|
| POST | `/register` | 201 | 422, 429 |
| POST | `/login` | 200 | 422, 429 |
| GET | `/user` | 200 | 401 |
| POST | `/logout` | 204 | 401 |
| POST | `/follow/{user_id}` | 201 (200 if already following) | 404, 422 (self) |
| DELETE | `/follow/{user_id}` | 200 | 404, 422 (self) |
| POST | `/posts` | 201 | 422 |
| GET | `/posts/{id}` | 200 | 404 |
| DELETE | `/posts/{id}` | 204 | 403 (not owner), 404 |
| GET | `/feed?limit=20&cursor=` | 200 | 422 |
| POST | `/posts/{id}/like` | 201 (200 if already liked) | 404 |
| DELETE | `/posts/{id}/like` | 200 | 404 |

Every authenticated endpoint can also return 401 and 429.

```bash
# register → {"data": {"user": {...}, "token": "1|..."}}
curl -X POST localhost:8080/api/register -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Jane","email":"jane@example.com","password":"secret123"}'

curl -X POST localhost:8080/api/follow/2 -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

curl -X POST localhost:8080/api/posts -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H 'Accept: application/json' -d '{"content":"hello world"}'

curl "localhost:8080/api/feed?limit=2" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Feed response (`GET /posts/{id}` returns a single item of the same shape):

```json
{
  "data": [
    {
      "id": 4,
      "content": "hello world",
      "author": {"id": 2, "name": "Jane", "created_at": "2026-10-15T10:42:41+00:00"},
      "likes_count": 1,
      "liked_by_me": true,
      "created_at": "2026-10-15T10:42:41+00:00",
      "updated_at": "2026-10-15T10:42:41+00:00"
    }
  ],
  "links": {"first": null, "last": null, "prev": null, "next": "http://localhost:8080/api/feed?limit=2&cursor=eyJ..."},
  "meta": {"path": "http://localhost:8080/api/feed", "per_page": 2, "next_cursor": "eyJ...", "prev_cursor": null}
}
```

Like/unlike return `{"data": {"post_id": 4, "likes_count": 1, "liked_by_me": true}}`.
Errors use Laravel's standard JSON shape (`message`, plus `errors` for 422). Other
users' emails are never exposed.

## Architecture

- **Thin controllers, service layer.** Controllers handle HTTP only; business logic
  lives in `FeedService`, `PostService`, `LikeService` and `FollowService`.
  Validation is in Form Requests, authorization in `PostPolicy`, response shaping in
  API Resources. Eloquent is the data-access layer, so there are no repositories on top
  of it.
- **Constraints in the database, not just in code.** Unique keys on follows, likes
  and notifications plus a `CHECK` against self-follows mean concurrent duplicate
  requests turn into idempotent no-ops (`insertOrIgnore`) instead of bad data.
- **No denormalized counters yet.** Like counts are grouped aggregates behind a
  cache. A `likes_count` column updated by a queued job is the next step once
  counts become hot.
- **Auth:** Sanctum personal access tokens. Login failures return the same response
  for unknown email and wrong password, with equal bcrypt cost on both paths.

## Database & indexes

| Table | Indexes | Used by |
|---|---|---|
| `posts` | `(user_id, created_at, id)` | per-author scans in the feed join; backs the FK |
| | `(created_at, id)` | feed ordering and cursor range scans |
| `follows` | `UNIQUE (follower_id, followee_id)` | feed join (covering), duplicate prevention |
| | `(followee_id, follower_id)` | notification fan-out (covering) |
| | `CHECK (follower_id <> followee_id)` | self-follow prevention |
| `likes` | `UNIQUE (user_id, post_id)` | duplicate prevention, `liked_by_me` lookup |
| | `(post_id, user_id)` | per-post counts |
| `post_notifications` | `UNIQUE (post_id, follower_id)` | idempotent fan-out |
| | `(follower_id)` | a user's notification inbox |

## Feed

```sql
SELECT posts.* FROM posts
JOIN follows ON follows.followee_id = posts.user_id AND follows.follower_id = :me
WHERE (posts.created_at, posts.id) < (:cursor_created_at, :cursor_id)   -- omitted on page 1
ORDER BY posts.created_at DESC, posts.id DESC
LIMIT :limit + 1
```

- **Cursor pagination, not OFFSET.** The cursor is the `(created_at, id)` of the last
  row, so every page is an index range scan whose cost doesn't depend on depth. Ties
  on `created_at` are broken by `id`, so pages never overlap or skip, even while new
  posts are inserted. Locally, a page at depth 5,000 took 0.36 ms with a cursor vs
  8.5 ms with `OFFSET`.
- **No N+1.** A page costs a fixed number of queries no matter how many posts or
  authors it contains: the feed query, one eager load of authors, one grouped like
  count (only for cache misses), and one `liked_by_me` lookup. A test asserts the
  query count is the same for 5 and 25 authors.
- `limit` defaults to 20, max 50. Invalid or tampered cursors fall back to the first
  page.

## Caching

Redis, with MySQL always the source of truth.

**Feed pages:** key `feed:{user}:v{version}:{limit}:{cursor}`, TTL 30 s. Only the
ordered post IDs and the next/prev cursors are cached. On a hit, posts and authors
are loaded with `whereIn`, and like data is fetched fresh. So a cached page can't
leak one user's `liked_by_me` to another, and deleted posts just drop out.
Follow/unfollow increments the user's `version` (one `INCR`), which invalidates all
their cached pages without scanning keys. New posts from people you already follow
appear within the TTL. That's a deliberate tradeoff, to avoid invalidating every
follower's cache on each post.

**Like counts:** key `post:{id}:likes_count`, TTL 600 s. A feed page reads all counts
with one `MGET` and computes any misses with a single grouped `COUNT`. A like or
unlike that actually changes state deletes the key; counts are never
incremented/decremented in Redis, so they can't drift or go negative. Like/unlike
responses always return a fresh count. `liked_by_me` is per-user and never cached.

## Queue & notifications

1. `POST /posts` saves the post, dispatches `FanOutPostNotifications($postId)`
   after the transaction commits, and returns 201 straight away. If the queue is
   down, the post still saves and the error is logged.
2. The fan-out job walks the author's followers in chunks of 500 using keyset
   pagination on `(followee_id, follower_id)`, and dispatches one
   `NotifyFollowerChunk` per chunk. Memory stays flat no matter how many
   followers there are.
3. Each chunk job bulk-inserts notification rows with `insertOrIgnore` against
   `UNIQUE (post_id, follower_id)`, so retries and duplicate runs never
   double-notify.

Jobs carry only IDs, retry 5 times with backoff (10s → 300s), and skip posts deleted
in the meantime. Splitting into chunks means one big account's fan-out spreads
across many workers instead of tying up one job. Jobs that exhaust their retries land
in `failed_jobs`. A transactional outbox would make delivery fully guaranteed, and
that's the next step.

## Rate limiting

| Limiter | Applies to | Limit (per minute) | Keyed by |
|---|---|---|---|
| `login` | `POST /login` | 5 | email + IP |
| `register` | `POST /register` | 10 | IP |
| `api` | all authenticated endpoints | 120 | user |
| `writes` | follow, post, like (create/delete) | 30 | user |

Writes count against both `api` and `writes`, so a client can't flood posts or likes
while staying under the general limit.

## Scaling to 5M users / 500k posts per day

500k posts/day is about 6 posts/s on average, and maybe 100–300/s at peak. Writes
are cheap. The hard part is feed reads and fan-out volume.

**Feed generation.** The current pull model (query followed authors at read time)
is right while follow lists are moderate, since nothing is precomputed. At scale I'd
move to a **hybrid push/pull** model:

- *Push* for normal authors: on post creation, the existing chunked fan-out writes
  the post ID into each follower's timeline, a Redis sorted set
  `timeline:{user}` scored by timestamp and trimmed to the latest ~800 entries.
  Reading a feed becomes `ZREVRANGEBYSCORE` + `whereIn` on primary keys.
- *Pull* for celebrities (e.g. > 10k followers): pushing to millions of
  timelines per post is too expensive, so their recent posts are fetched at read
  time and merged into the timeline.
- Inactive users skip fan-out; their timeline is rebuilt on next login.

**Avoiding slow queries.**
- Cursor pagination everywhere, never OFFSET.
- Every hot query is backed by a composite or covering index (table above), and
  every list is bounded by a hard `LIMIT`.
- Fixed per-request query budget: eager loading and batched `whereIn`/`GROUP BY`
  instead of per-row queries.
- Read replicas for feed/post reads; the primary handles writes.
- Partition `posts` by month, or shard by `user_id`, once it reaches billions of
  rows. Archive old notifications.
- Keep joins narrow: the feed join is index-only on `follows`. With push timelines
  it disappears entirely.

**When to cache.**
- Data that is read far more than it changes and can tolerate seconds of
  staleness: feed pages, like counts, user profiles, timelines.
- Not for per-viewer flags (`liked_by_me`) or anything that must be exact after a
  write (mutation responses read from the DB).
- Invalidate with versioned keys or deletes, never by updating values in place.

**Queues.** Separate queues for fan-out and other jobs so a celebrity post can't
delay everything else. Workers scale horizontally (Horizon), and queue depth and
failure rate are the alerting signals.

## Testing

90 Pest feature tests (339 assertions) cover:
- auth and rate limits
- follow rules and constraints
- post CRUD and authorization
- feed ordering, tie-breaking, multi-page walks and isolation
- N+1 query-count guards
- like idempotency and cache behaviour
- feed-cache isolation and invalidation
- the notification pipeline: after-commit dispatch, chunking, retry dedup, deleted
  posts, queue outage

Tests run against MySQL (not SQLite), so the real constraints and query plans are
exercised. The test bootstrap refuses to run against any database other than
`pulsefeed_testing`.

## Benchmark data

```bash
php artisan pulsefeed:seed-benchmark --fresh   # 100k users, 1M posts, ~1M follows, 2M likes
```

The command seeds with batched `INSERT … SELECT` over recursive CTEs (about 70 s
locally for the defaults). It also creates `bench-sub-500@bench.local`,
`bench-sub-5000@bench.local` and `bench-sub-50000@bench.local` (password
`sup3r-secret`), which follow 500 / 5k / 50k authors.

Measured on a laptop as `bench-sub-50000` with 1M posts: first feed page 44 ms cold,
15 ms cached, and a cursor page at depth 500,000 in under 5 ms. These are
single-machine numbers that show query-plan behaviour, not production capacity.
