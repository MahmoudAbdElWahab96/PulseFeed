<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createPostAt(User $author, string $when, string $content = 'post'): Post
{
    $post = Post::factory()->for($author, 'author')->make(['content' => $content]);
    $post->created_at = Carbon::parse($when);
    $post->updated_at = Carbon::parse($when);
    $post->save();

    return $post;
}

it('rejects unauthenticated feed access', function () {
    $this->getJson('/api/feed')->assertUnauthorized();
});

it('returns an empty feed when nobody is followed', function () {
    $user = User::factory()->create();
    Post::factory()->count(3)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/feed');

    $response->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.next_cursor', null);
});

it('returns an empty feed when followed authors have no posts', function () {
    $user = User::factory()->create();
    $user->following()->attach(User::factory()->count(2)->create());

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('returns posts from followed authors newest first across authors', function () {
    $subscriber = User::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $stranger = User::factory()->create();

    $subscriber->following()->attach([$alice->id, $bob->id]);

    createPostAt($alice, '2026-01-01 10:00:00', 'alice old');
    createPostAt($bob, '2026-01-01 12:00:00', 'bob new');
    createPostAt($alice, '2026-01-01 11:00:00', 'alice mid');
    createPostAt($stranger, '2026-01-01 13:00:00', 'stranger newest');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonPath('data.0.content', 'bob new')
        ->assertJsonPath('data.1.content', 'alice mid')
        ->assertJsonPath('data.2.content', 'alice old')
        ->assertJsonCount(3, 'data');
});

it('excludes the subscriber\'s own posts from their feed', function () {
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);

    createPostAt($subscriber, '2026-01-01 12:00:00', 'my own newest');
    createPostAt($author, '2026-01-01 10:00:00', 'followed author');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.content', 'followed author');
});

it('isolates feeds between users', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $authorOnlyAliceFollows = User::factory()->create();
    $authorBothFollow = User::factory()->create();

    $alice->following()->attach([$authorOnlyAliceFollows->id, $authorBothFollow->id]);
    $bob->following()->attach([$authorBothFollow->id]);

    createPostAt($authorOnlyAliceFollows, '2026-01-01 10:00:00', 'alice only');
    createPostAt($authorBothFollow, '2026-01-01 11:00:00', 'both');

    $this->actingAs($bob, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.content', 'both');
});

it('breaks created_at ties deterministically by newest id', function () {
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);

    createPostAt($author, '2026-01-01 10:00:00', 'first inserted');
    createPostAt($author, '2026-01-01 10:00:00', 'second inserted');
    createPostAt($author, '2026-01-01 10:00:00', 'third inserted');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonPath('data.0.content', 'third inserted')
        ->assertJsonPath('data.1.content', 'second inserted')
        ->assertJsonPath('data.2.content', 'first inserted');
});

it('paginates without duplicates or gaps across cursors, including ties', function () {
    $subscriber = User::factory()->create();
    $authors = User::factory()->count(3)->create();
    $subscriber->following()->attach($authors);

    // Several posts share a timestamp to exercise the compound cursor.
    foreach (range(1, 25) as $i) {
        createPostAt($authors[$i % 3], '2026-01-01 '.sprintf('%02d', intdiv($i, 2)).':00:00', "post {$i}");
    }

    $seen = [];
    $cursor = null;
    $pages = [];

    do {
        $query = '/api/feed?limit=10'.($cursor ? "&cursor={$cursor}" : '');
        $response = $this->actingAs($subscriber, 'sanctum')->getJson($query);
        $response->assertOk();

        $pages[] = count($response->json('data'));
        $seen = array_merge($seen, $response->json('data'));

        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($pages)->toBe([10, 10, 5])
        ->and(count($seen))->toBe(25)
        ->and(count(array_unique(array_column($seen, 'id'))))->toBe(25);

    $walkedIds = array_column($seen, 'id');
    $expected = Post::query()
        ->whereIn('user_id', $authors->pluck('id'))
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->pluck('id')
        ->all();

    expect($walkedIds)->toBe($expected);
});

it('bounds the page size and defaults to 20', function () {
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);

    foreach (range(1, 30) as $i) {
        createPostAt($author, '2026-01-01 00:'.sprintf('%02d', $i).':00', "post {$i}");
    }

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonCount(20, 'data');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed?limit=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed?limit=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5);
});

it('treats a malformed cursor as the first page', function () {
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);
    createPostAt($author, '2026-01-01 10:00:00', 'only post');

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed?cursor='.urlencode('not-a-valid-cursor'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('issues a fixed number of queries regardless of page contents (no N+1)', function () {
    $subscriber = User::factory()->create();
    $authors = User::factory()->count(5)->create();
    $subscriber->following()->attach($authors);
    foreach ($authors as $author) {
        createPostAt($author, '2026-01-01 10:00:00', 'post by '.$author->id);
    }

    $countQueries = function () use ($subscriber) {
        // Both measurements must take the uncached path.
        Cache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $withFiveAuthors = $countQueries();

    // If author resolution or like metadata were per-post, the query
    // count would grow with the page.
    $moreAuthors = User::factory()->count(20)->create();
    $subscriber->following()->attach($moreAuthors);
    foreach ($moreAuthors as $author) {
        createPostAt($author, '2026-01-02 10:00:00', 'post by '.$author->id);
    }

    $withTwentyFiveAuthors = $countQueries();

    expect($withFiveAuthors)->toBeLessThanOrEqual(4)
        ->and($withTwentyFiveAuthors)->toBe($withFiveAuthors);
});
