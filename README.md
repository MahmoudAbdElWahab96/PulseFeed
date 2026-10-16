# PulseFeed — News Feed API

A production-minded REST API for a simplified Twitter/Instagram-style feed: accounts,
following, posts, a personalized cursor-paginated feed, likes, Redis caching, and
asynchronous follower notifications via queued jobs. Built as a modular monolith in
Laravel with MySQL and Redis.

> Assignment submission for Augustus Media — Senior Backend Developer.
> Every measured number in this document lists the environment it was measured in.
> The Docker Compose stack was built and executed on the development machine
> (Docker Engine 29.5.2) after the initial native development; see [Docker](#docker)
> for exactly what was and was not verified inside containers.

## Implemented features

- Registration, login, logout (Sanctum personal access tokens), current-user endpoint.
- Login throttling: 5 attempts/min per normalized email + IP, generic 422 bodies that
  do not reveal whether an email exists (with timing-equalized bcrypt failures).
- Follow / unfollow with idempotent duplicate handling; self-follow is rejected at the
  validation layer *and* by a database CHECK constraint.
- Post creation, retrieval, owner-only deletion (policy + 403).
- Personalized feed: posts from followed users only, `created_at DESC, id DESC`
  deterministic ordering, cursor pagination, bounded page size (default 20, max 50).
- `likes_count` and per-user `liked_by_me` on posts and feed items, computed in
  batched queries — never per post.
- Redis caching for like counts and feed pages, invalidation-only semantics.
- Asynchronous notification fan-out to followers through queued, chunked, idempotent
  jobs on the Redis queue.
- Pest test suite covering all endpoints, constraints, cache behavior, pagination
  correctness, N+1 regression guards, and job idempotency.

## Stack and versions (verified locally)

| Component | Version used | Notes |
|---|---|---|
| PHP | 8.4.23 | extensions: `pdo_mysql`, `phpredis` |
| Laravel | 13.36.0 | Sanctum 4.x API tokens |
| MySQL | 26.7.0 (Homebrew) | dev machine; compose pins `mysql:8.4` LTS |
| Redis | 8.10.1 (Homebrew) | compose pins `redis:8` |
| Pest | 4.7 | via `composer test` / `vendor/bin/pest` |

## Local setup (native)

```bash
git clone <repository> pulsefeed && cd pulsefeed
composer install
cp .env.example .env
php artisan key:generate

# Create the databases (adjust credentials for your MySQL)
mysql -u root -e "CREATE DATABASE pulsefeed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE pulsefeed_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --force      # dev database
DB_DATABASE=pulsefeed_testing php artisan migrate --force   # test database (see phpunit.xml)

php artisan serve                # http://127.0.0.1:8000
php artisan queue:work redis     # in a second terminal: notification fan-out worker
```

`.env` defaults expect MySQL on 127.0.0.1 with an unprivileged-to-root user and Redis
on 127.0.0.1:6379 (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`). Optional tunables:
`LIKES_COUNT_CACHE_TTL` (default 600 s) and `FEED_PAGE_CACHE_TTL` (default 30 s).

### Commands

| Command | Purpose |
|---|---|
| `php artisan serve` | Run the API |
| `php artisan queue:work redis` | Process notification jobs |
| `composer test` / `vendor/bin/pest` | Full test suite (needs `pulsefeed_testing` DB) |
| `vendor/bin/pint` | Code style |
| `php artisan route:list --path=api` | Endpoint inventory |

## Docker

`docker-compose.yml` defines five services: `nginx` (port 8080), `app` (php:8.4-fpm,
runs `migrate --force` via entrypoint before serving), `queue` (`queue:work redis`),
`mysql:8.4`, and `redis:8` — with volumes, health checks, and Laravel configuration
injected through environment variables that override `.env`.

**Verified on the development machine** (Docker Engine 29.5.2, macOS host) after the
native development was complete:

- `docker compose up -d --build` brought up all five services; `mysql` and `redis`
  reported healthy per their health checks.
- The full request path through nginx + php-fpm worked: `/up` health check 200,
  registration, login, follow (201), post creation (201) with the complete resource
  shape (`likes_count`, `liked_by_me`), and like (201 with count).
- The `queue` container executed the real fan-out end to end: `FanOutPostNotifications`
  → `NotifyFollowerChunk` in `docker compose logs queue`, and the notification row
  appeared in the containerized MySQL for the follower (not the author).
- Each container runs its own MySQL/Redis data (dev bind-mounted source, prod-baked
  vendor volume), so the Docker environment is independent of the native one.

What this does **not** prove: production deployment hardening (resource limits,
secrets management, image scanning, restart-under-load) — this was a single-host
functional verification.

```bash
docker compose up -d --build
curl http://localhost:8080/api/register -H 'Content-Type: application/json' \
  -d '{"name":"A","email":"a@b.co","password":"password1"}'
```

## API reference

Base URL: `http://127.0.0.1:8000/api` (native) or `http://localhost:8080/api` (Compose).
All endpoints require and return JSON. Authenticated endpoints expect
`Authorization: Bearer <token>`.

| Method & path | Auth | Success | Errors |
|---|---|---|---|
| `POST /register` | — | 201 | 422 validation |
| `POST /login` | — | 200 | 422 bad credentials, 429 throttled |
| `GET /user` | token | 200 | 401 |
| `POST /logout` | token | 204 (revokes current token only) | 401 |
| `POST /follow/{user_id}` | token | 201 first / 200 duplicate | 401, 404, 422 self-follow |
| `DELETE /follow/{user_id}` | token | 200 (idempotent) | 401, 404, 422 self |
| `POST /posts` | token | 201 | 401, 422 |
| `GET /posts/{id}` | token | 200 | 401, 404 |
| `DELETE /posts/{id}` | token | 204 (owner only) | 401, 403, 404 |
| `GET /feed?limit=&cursor=` | token | 200 | 401, 422 bad limit |
| `POST /posts/{id}/like` | token | 201 first / 200 duplicate | 401, 404 |
| `DELETE /posts/{id}/like` | token | 200 (idempotent) | 401, 404 |

### Examples

```bash
# Register → 201 {"data":{"user":{...},"token":"3|plainToken..."}}
curl -s -X POST :8000/api/register -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Jane","email":"jane@t.co","password":"sup3r-secret"}'

# Follow → 201 {"data":{"user_id":2,"following":true}}  (duplicate: 200)
curl -s -X POST :8000/api/follow/2 -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# Create post → 201 (fan-out is queued; the response does not wait for it)
curl -s -X POST :8000/api/posts -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H 'Accept: application/json' -d '{"content":"hello world"}'

# Feed → 200 (cursor pagination)
curl -s ":8000/api/feed?limit=2" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Feed response shape (post detail uses the same item shape without `meta`/`links`):

```json
{
  "data": [
    {
      "id": 4,
      "content": "post one",
      "author": {"id": 2, "name": "Author", "created_at": "2026-10-15T10:42:41+00:00"},
      "likes_count": 1,
      "liked_by_me": true,
      "created_at": "2026-10-15T10:42:41+00:00",
      "updated_at": "2026-10-15T10:42:41+00:00"
    }
  ],
  "links": {"first": null, "last": null, "prev": null, "next": null},
  "meta": {
    "path": "http://127.0.0.1:8000/api/feed",
    "per_page": 2,
    "next_cursor": "eyJwb3N0cy5jcmVhdGVkX2F0Ijoi...",
    "prev_cursor": null,
    "next_page_url": "http://127.0.0.1:8000/api/feed?limit=2&cursor=eyJ...",
    "prev_page_url": null
  }
}
```

Like/unlike return `{"data":{"post_id":4,"likes_count":1,"liked_by_me":true}}`.
Errors follow Laravel conventions: `401 {"message":"Unauthenticated."}`,
`422 {"message":..., "errors":{field:[...]}}`, `403`, `404`.
Author objects never include `email` unless they are the requesting user.
The `content` field is required, 1–1000 characters (matching the column).

## Database schema

Four domain tables on top of the Laravel/Sanctum defaults (`users`,
`personal_access_tokens`, cache/jobs tables):

**`posts`** — `id`, `user_id → users CASCADE`, `content VARCHAR(1000)`, timestamps.
Indexes: `(user_id, created_at, id)` (feed join per author; also backs the FK) and
`(created_at, id)` (the deterministic cursor order).

**`follows`** — `id`, `follower_id`/`followee_id → users CASCADE`, timestamps.
`UNIQUE (follower_id, followee_id)` (duplicate follows impossible; drives the feed
join), `INDEX (followee_id, follower_id)` (fan-out enumeration), and
`CHECK (follower_id <> followee_id)` (self-follow impossible at the storage layer;
MySQL ≥ 8.0.16 enforces it — verified directly with `ERROR 3819`).

**`likes`** — `id`, `user_id`/`post_id → CASCADE`, timestamps.
`UNIQUE (user_id, post_id)` (duplicate likes impossible under any concurrency),
`INDEX (post_id, user_id)` (per-post counts and batched `liked_by_me` lookups).

**`post_notifications`** — `id`, `post_id`/`follower_id → CASCADE`, timestamps.
`UNIQUE (post_id, follower_id)` (idempotent fan-out; job retries can never
double-notify), `INDEX (follower_id)` (inbox reads).

All constraints were verified functionally against MySQL: duplicate follow → `1062`,
duplicate like → `1062`, orphan post → `1452`, self-follow → `3819`.

## Feed design

The feed query joins `follows` to `posts` and orders deterministically:

```sql
SELECT posts.* FROM posts
INNER JOIN follows ON follows.followee_id = posts.user_id AND follows.follower_id = ?
WHERE (posts.created_at < ? OR (posts.created_at = ? AND (posts.id < ?)))
ORDER BY posts.created_at DESC, posts.id DESC
LIMIT 21   -- limit + 1, the extra row drives hasMore
```

- **Determinism:** the composite `(created_at DESC, id DESC)` order makes ties on
  `created_at` break by `id`; the cursor is exactly that tuple (opaque base64).
  Pages never overlap or skip, and remain stable under concurrent inserts —
  verified by tests that walk multi-page feeds with tied timestamps and compare
  against global order.
- **N+1 prevention:** every feed page costs a fixed query budget regardless of
  content — ranking join, one eager author load, one grouped like-count aggregate,
  one `liked_by_me` existence query. A regression test proves the count is
  identical for 5 vs 25 distinct authors and ≤ 4.
- **Measured plans** (local dev MySQL, ~10k posts / 500 follows, no concurrent load;
  small numbers reflect a warm local server, not production capacity):
  - First page: backward index scan of `posts_created_id_idx` reading 20 rows +
    `eq_ref` follow-membership check on `follows_follower_followee_uniq` (covering).
    **0.26 ms** average over 20 runs.
  - Deep cursor page (depth ≈ 5000): range scan on the same index — **0.36 ms**.
  - Equivalent `OFFSET 5000` page: **8.46 ms** — cursor pagination is ~23× faster
    at that depth and the gap grows with depth.
- Malformed cursors decode to `null` and are served the first page (lenient,
  documented, tested).

## Caching

Cache store: Redis (`CACHE_STORE=redis`; the cache connection uses db 1 locally —
verified with `redis-cli`). The database is always the source of truth.

**Like counts** — key `post:{id}:likes_count`, TTL 600 s (`LIKES_COUNT_CACHE_TTL`).
Feed pages read counts with one `Cache::many` (a single Redis MGET) plus one grouped
`COUNT(*)` for misses; misses are written back with one SET per uncached post
(bounded by page size; Redis pipelining is a future optimization). `liked_by_me` is
**never cached** — it is user-specific and computed per request via one indexed query.

*Invalidation is delete-only:* a like/unlike that changes database state deletes the
key after the statement commits; a duplicate like or no-op unlike touches nothing.
Counts are never incremented or decremented in Redis, so a cached count can never go
negative or compound errors. Mutation endpoints always respond with a fresh `COUNT(*)`.

*Consistency window (deliberate):* a reader that recomputes a count concurrently with
a writer's insert can repopulate a stale value after the writer's invalidation; it
persists until the TTL expires or the next real state change. This window is inherent
to invalidation-only caching and is demonstrated by a test that injects a stale value
and shows the documented behavior. Stronger consistency would require write-through
counters or versioned reads — not worth the complexity for like counts.

**Feed pages** — key `feed:{user_id}:v{version}:{limit}:{cursor_hash}`, TTL 30 s
(`FEED_PAGE_CACHE_TTL`). The cached payload is minimal: the ordered post IDs plus the
exact cursor bookends the original page produced. Cache hits rehydrate models,
authors, and like metadata with fresh queries, so no user's entry can be served to
another user and per-user data (`liked_by_me`) is never replayed — covered by an
isolation test. `HydratedFeedPage` (a `CursorPaginator` subclass) replays the cached
cursor strings so `meta`/`links` are byte-identical to a fresh page.

*Invalidation:* follow/unfollow bumps a per-user version key — one O(1) `INCR`
orphaning all of that user's pages without key scans (tested: a new author appears
immediately despite the TTL). Posts deleted after caching simply drop out during
hydration. **New posts from already-followed authors are not invalidated in this
version** (no fan-out-on-write for cache): the maximum feed staleness is the TTL
(30 s). This is the documented tradeoff for not running invalidation fan-out on
every post create.

## Notifications

Lifecycle: `POST /api/posts` persists the post and returns 201 immediately;
`FanOutPostNotifications(postId)` is dispatched `->afterCommit()` (after the creating
transaction commits; a queue outage degrades to "post saved, fan-out lost + logged"
rather than failing the write — tested with a throwing dispatcher). A worker runs the
walker, which reads followers and dispatches chunk jobs; chunk jobs insert
notification rows.

- **Payloads are scalar**: the walker carries only `postId`; each chunk job carries
  `postId` + ≤ 500 integer follower IDs.
- **Chunking:** `WHERE followee_id = ? AND follower_id > :cursor ORDER BY follower_id
  LIMIT 500` — a covering range scan on `follows_followee_follower_idx` (EXPLAIN
  verified on 80k follows / 200 followees: covering index, no filesort; 0.28 ms per
  500-id chunk, local dev DB). Never an OFFSET, never the full follower list in
  memory.
- **Idempotency:** chunks write `insertOrIgnore` against `UNIQUE (post_id,
  follower_id)`; retrying any chunk — or the whole walker — cannot create duplicates
  (tested by running the full fan-out twice). Deleted posts are detected and skipped
  with an informational log.
- **Retries/backoff:** 5 attempts, backoff 10/30/60/120/300 s, timeouts 300 s (walker)
  and 60 s (chunk).
- **Guarantees, precisely:** duplicates are impossible; delivery is guaranteed *if*
  every dispatched job eventually succeeds. A persistent Redis/MySQL outage that
  exhausts all retries leaves the un-dispatched tail in `failed_jobs` with **no
  automatic reconciliation** — a transactional outbox plus a periodic `fan-out:resume`
  command is the recommended future fix.
- **Follower-selection semantics:** followers are selected *while fan-out runs*, not
  snapshotted at post-creation time. A user who follows after the post is created but
  before the walker passes their ID range is notified; a user who unfollows before
  their chunk is processed is not.

## Testing

`vendor/bin/pest` → **80 tests, 293 assertions, all passing** (MySQL `pulsefeed_testing`
database, array cache, `database` queue connection). Coverage includes auth and
throttling, follow integrity and self-follow rejection, post CRUD and policy,
feed ordering/ties/pagination-walks/isolation, N+1 regression guards, like
idempotency and cache behavior, feed-cache isolation and invalidation, and the full
notification matrix (dispatch-after-commit observed through the real database queue
driver, chunk partitioning, retry dedup, deleted posts, crash recovery).

Four levels of verification were used, and they are not interchangeable:

1. **Automated Pest suite** — logic and contracts, on MySQL + array cache +
   database queue. `Queue::fake()` proves dispatch wiring but *cannot* demonstrate
   after-commit deferral (the fake records pushes immediately); that property is
   tested against the real database queue driver instead.
2. **Live HTTP smoke tests** — every endpoint exercised with real tokens via
   `php artisan serve` + `curl` during development (status codes and bodies
   spot-checked in each phase).
3. **Real Redis checks** — like-count keys (`post:{id}:likes_count`, TTL 600),
   feed page keys (`feed:{id}:v{n}:20:first`, TTL 30), MGET batching, invalidation
   on unlike, and an actual `queue:work redis` run processing walker + chunk to
   produce notification rows.
4. **Docker Compose** — built and run on the development machine (Docker Engine
   29.5.2): full request path through nginx + php-fpm, plus the queue worker
   processing the fan-out inside containers. Not a production-hardening test.

## Architecture decisions and tradeoffs

- **Modular monolith**, thin controllers, services only where logic earns them
  (`Follow`, `Feed`, `Like`, `Post`), Form Requests for validation, one Policy
  (post deletion), API Resources for responses. No repositories, no event-sourcing.
- **Denormalized nothing.** Like counts are computed (grouped aggregates), keeping
  correctness trivial; a counter column is the documented next step if counts ever
  become hot.
- **Constraints over code:** uniqueness, self-follow prevention, and referential
  integrity all live in the schema; application code races degrade into idempotent
  no-ops instead of corruption.
- **Known limitations:** like-count staleness window (TTL-bounded); feed staleness
  for new posts (TTL-bounded); no automatic reconciliation of exhausted fan-out
  retries; no rate limiting on write endpoints; single-region single-instance
  assumptions (no horizontal Redis/MySQL topology).

### Scaling to 5M users / 500k posts per day — honestly

500,000 posts/day averages **≈ 5.8 posts/second**; realistic peaks (timezones,
viral content) can multiply that by 10–50×. The reasoning below explains design
choices — it does **not** constitute proof of capacity.

**Why pull-based is a reasonable start.** With cursor pagination and the composite
indexes, one feed page is an index-ordered scan with a bounded per-page cost that
does not grow with offset depth (measured locally: sub-millisecond at depth 5000 on
a 10k-post dataset — indicative of plan shape, not capacity). For mostly-smaller
follow graphs, pull avoids the write amplification of maintaining per-follower
timelines for every post.

**Why celebrities break pure pull.** A pull feed for a user following a 5M-follower
account must scan that account's stream within the candidate time window and
intersect it with follow membership; the cost grows with the product of followed
volume and fan density, and merges across many followed high-volume accounts get
expensive.

**The documented hybrid strategy (future, not implemented):** classify accounts by
follower count (e.g., > 10–50k = "celebrity"). Feed assembly merges (a) pushed,
pre-materialized timeline entries for normal authors — written at post-create time
by the existing chunked fan-out worker — with (b) on-the-fly pulls for celebrity
authors, plus (c) the per-user page cache that already exists. Today's pieces map
onto that path deliberately: chunked idempotent jobs, per-user feed versioning, and
cursor-stable caching. Introducing it needs capacity measurements first.

**What Redis and queues buy at this scale:** the queue absorbs the fan-out write
burst (500k posts/day × average followers is the real write volume; the walker
already batches it into 500-follower chunks); Redis absorbs hot reads (counts,
pages) and defers ranking work. Backlog depth (`queues:default` length), job
throughput, and retry counts are the signals to alert on.

**What the local measurements do not prove.** Everything measured here ran on a
single developer machine (Homebrew MySQL/Redis, warm caches, single connection, no
concurrent load) against ≤ 80k rows. The measurements demonstrate plan shape and
constant-per-page behavior — not throughput, not behavior under production
contention, not multi-node topology.

**Before claiming production readiness:** load tests at target RPS (k6/Locust:
feed reads, like storms, post-create bursts with realistic follow graphs),
concurrency tests for constraint races, MySQL sizing and replication review, Redis
memory/topology planning, queue backlog and failure monitoring, a reconciliation
mechanism for exhausted fan-out, and capacity planning tied to measured percentiles
rather than averages.
