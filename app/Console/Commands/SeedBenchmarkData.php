<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bulk-seeds a benchmark dataset with batched raw SQL over recursive CTEs;
 * Eloquent factories would take hours at these volumes.
 *
 * Random-looking values come from deterministic multiplicative hashing of
 * (row, batch) numbers, not SQL RAND(): inside INSERT ... SELECT over a
 * recursive CTE, RAND()-based pairs collapse to a fraction of their
 * expected distinct count (observed 61% per batch locally). Ids are
 * explicit and gap-free because AUTO_INCREMENT burns values on failed
 * statements, and downstream hashing relies on contiguous ranges.
 *
 * Additive by default; --fresh truncates first.
 */
#[Signature('pulsefeed:seed-benchmark
    {--users=100000 : Number of regular users to seed}
    {--posts=1000000 : Total posts to seed, spread across users}
    {--follows=1000000 : Approximate follow relationships to seed}
    {--likes=2000000 : Approximate likes to seed}
    {--celebrities=10 : Users whose ids receive a concentrated follower share}
    {--chunk=10000 : Rows per batch statement}
    {--fresh : Truncate all domain tables (and tokens) before seeding}
    {--skip-bench-subscribers : Do not create the fixed benchmark subscriber accounts}
')]
#[Description('Seed bulk benchmark data (users, posts, follows, likes) for load testing')]
class SeedBenchmarkData extends Command
{
    private const PASSWORD = 'sup3r-secret';

    /** Email => number of authors followed. */
    private const BENCH_SUBSCRIBERS = [
        'bench-sub-500@bench.local' => 500,
        'bench-sub-5000@bench.local' => 5000,
        'bench-sub-50000@bench.local' => 50000,
    ];

    public function handle(): int
    {
        $users = max(1, (int) $this->option('users'));
        $posts = max(1, (int) $this->option('posts'));
        $followsBudget = max(0, (int) $this->option('follows'));
        $likesBudget = max(0, (int) $this->option('likes'));
        $celebrities = min(max(1, (int) $this->option('celebrities')), $users);
        $chunk = max(100, (int) $this->option('chunk'));

        if ($this->option('fresh')) {
            $this->warn('Truncating domain tables (--fresh)...');
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['post_notifications', 'likes', 'follows', 'posts', 'personal_access_tokens', 'users'] as $table) {
                DB::table($table)->truncate();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $base = (int) (User::query()->max('id') ?? 0);
        $postBase = (int) (Post::query()->max('id') ?? 0);
        $passwordHash = Hash::make(self::PASSWORD);

        DB::statement("SET SESSION cte_max_recursion_depth = {$chunk}");

        $started = now();
        $this->info("Seeding on top of user id {$base} (chunk {$chunk})...");

        $this->seedUsers($base, $users, $passwordHash, $chunk);
        $this->seedPosts($base, $users, $postBase, $posts, $chunk);
        $this->seedFollows($base, $users, $celebrities, $followsBudget, $chunk);
        $this->seedLikes($base, $users, $postBase, $posts, $likesBudget, $chunk);

        if (! $this->option('skip-bench-subscribers')) {
            $this->seedBenchSubscribers($base, $users);
        }

        $this->report($started);

        return self::SUCCESS;
    }

    private function seedUsers(int $base, int $count, string $hash, int $chunk): void
    {
        $stage = now();

        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $n = min($chunk, $count - $offset);

            DB::statement(<<<SQL
                INSERT INTO users (id, name, email, password, created_at, updated_at)
                WITH RECURSIVE nums AS (
                    SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$n}
                )
                SELECT
                    {$base} + {$offset} + n,
                    CONCAT('bench user ', {$base} + {$offset} + n),
                    CONCAT('bench-', {$base} + {$offset} + n, '@bench.local'),
                    '{$hash}',
                    UTC_TIMESTAMP() - INTERVAL n SECOND,
                    UTC_TIMESTAMP() - INTERVAL n SECOND
                FROM nums
                SQL);

            $this->progress('users', $offset + $n, $count);
        }

        $this->stage('users', $stage);
    }

    private function seedPosts(int $base, int $users, int $postBase, int $count, int $chunk): void
    {
        $stage = now();
        $batch = 0;

        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $n = min($chunk, $count - $offset);
            $batch++;

            DB::statement(<<<SQL
                INSERT INTO posts (id, user_id, content, created_at, updated_at)
                WITH RECURSIVE nums AS (
                    SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$n}
                )
                SELECT
                    {$postBase} + {$offset} + n,
                    {$base} + (({$offset} + n - 1) MOD {$users}) + 1,
                    CONCAT('benchmark post ', {$offset} + n),
                    UTC_TIMESTAMP() - INTERVAL ((n * 7919 + {$batch} * 104729) MOD 43200) MINUTE,
                    UTC_TIMESTAMP()
                FROM nums
                SQL);

            $this->progress('posts', $offset + $n, $count);
        }

        $this->stage('posts', $stage);
    }

    private function seedFollows(int $base, int $users, int $celebrities, int $budget, int $chunk): void
    {
        if ($budget === 0) {
            return;
        }

        $stage = now();

        // The first C seeded users take 60% of the budget for a
        // heavy-tailed follower distribution; the rest is spread randomly.
        $celebrityBudget = (int) floor($budget * 0.6);
        $randomBudget = $budget - $celebrityBudget;
        $perCelebrity = max(1, intdiv($celebrityBudget, $celebrities));

        foreach (range(1, $celebrities) as $celebrity) {
            $followee = $base + $celebrity;

            for ($seeded = 0, $batch = 0; $seeded < $perCelebrity; $seeded += $chunk) {
                $n = min($chunk, $perCelebrity - $seeded);
                $batch++;

                // Followers drawn from non-celebrity ids only: no self-follow possible.
                DB::statement(<<<SQL
                    INSERT IGNORE INTO follows (follower_id, followee_id, created_at, updated_at)
                    WITH RECURSIVE nums AS (
                        SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$n}
                    )
                    SELECT
                        {$base} + {$celebrities} + 1 + ((n * 7919 + {$batch} * 104729 + {$celebrity} * 15485863) MOD ({$users} - {$celebrities} - 1)),
                        {$followee},
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    FROM nums
                    SQL);
            }

            $this->line("  celebrity {$celebrity}/{$celebrities} follower budget: {$perCelebrity}");
        }

        for ($seeded = 0, $batch = 0; $seeded < $randomBudget; $seeded += $chunk) {
            $n = min($chunk, $randomBudget - $seeded);
            $batch++;

            // The pair is built once in the inner SELECT so the WHERE
            // filters the values actually being inserted.
            DB::statement(<<<SQL
                INSERT IGNORE INTO follows (follower_id, followee_id, created_at, updated_at)
                WITH RECURSIVE nums AS (
                    SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$n}
                )
                SELECT follower, followee, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                FROM (
                    SELECT
                        {$base} + 1 + ((n * 7919 + {$batch} * 104729) MOD {$users}) AS follower,
                        {$base} + 1 + ((n * 31 + {$batch} * 97) * 65537 MOD {$users}) AS followee
                    FROM nums
                ) pairs
                WHERE pairs.follower <> pairs.followee
                SQL);

            $this->progress('follows (random)', $seeded + $n, $randomBudget);
        }

        $this->stage('follows', $stage);
    }

    private function seedLikes(int $base, int $users, int $postBase, int $posts, int $budget, int $chunk): void
    {
        if ($budget === 0) {
            return;
        }

        $stage = now();

        for ($seeded = 0, $batch = 0; $seeded < $budget; $seeded += $chunk) {
            $n = min($chunk, $budget - $seeded);
            $batch++;

            DB::statement(<<<SQL
                INSERT IGNORE INTO likes (user_id, post_id, created_at, updated_at)
                WITH RECURSIVE nums AS (
                    SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$n}
                )
                SELECT
                    {$base} + 1 + ((n * 7919 + {$batch} * 104729) MOD {$users}),
                    {$postBase} + 1 + ((n * 31 + {$batch} * 97) * 65537 MOD {$posts}),
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                FROM nums
                SQL);

            $this->progress('likes', $seeded + $n, $budget);
        }

        $this->stage('likes', $stage);
    }

    private function seedBenchSubscribers(int $base, int $users): void
    {
        $stage = now();
        $maxTake = max(self::BENCH_SUBSCRIBERS);

        DB::statement('SET SESSION cte_max_recursion_depth = '.($maxTake + 1000));

        foreach (self::BENCH_SUBSCRIBERS as $email => $authors) {
            $subscriber = User::query()->create([
                'name' => "bench subscriber {$authors}",
                'email' => $email,
                'password' => self::PASSWORD,
            ]);

            $count = min($authors, $users);

            DB::statement(<<<SQL
                INSERT IGNORE INTO follows (follower_id, followee_id, created_at, updated_at)
                WITH RECURSIVE nums AS (
                    SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < {$count}
                )
                SELECT {$subscriber->id}, {$base} + n, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                FROM nums
                SQL);

            $inserted = DB::table('follows')->where('follower_id', $subscriber->id)->count();

            $this->info("  {$email} follows {$inserted} authors (password: ".self::PASSWORD.')');
        }

        $this->stage('bench subscribers', $stage);
    }

    private function progress(string $stage, int $done, int $total): void
    {
        if ($done < $total) {
            return;
        }

        $this->line("  {$stage}: {$done}/{$total}");
    }

    private function stage(string $name, CarbonInterface $started): void
    {
        $this->info("  [done] {$name} in {$started->diffInSeconds(now())}s");
    }

    private function report(CarbonInterface $started): void
    {
        $counts = [
            'users' => DB::table('users')->count(),
            'posts' => DB::table('posts')->count(),
            'follows' => DB::table('follows')->count(),
            'likes' => DB::table('likes')->count(),
        ];

        $this->newLine();
        $this->table(array_keys($counts), [array_values($counts)]);
        $this->info('Total time: '.$started->diffInSeconds(now()).'s');

        $this->line('Next steps:');
        $this->line('  - login as bench-sub-500@bench.local (password: '.self::PASSWORD.') and request /api/feed');
        $this->line('  - deep-cursor and EXPLAIN examples live in README.md (Benchmark data)');
    }
}
