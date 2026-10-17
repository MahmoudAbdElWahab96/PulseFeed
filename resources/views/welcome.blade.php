@php
    $endpoints = [
        'Auth' => [
            ['POST', '/register', 'Create an account', false],
            ['POST', '/login', 'Get an access token', false],
            ['GET', '/user', 'Current user', true],
            ['POST', '/logout', 'Revoke the current token', true],
        ],
        'Follows' => [
            ['POST', '/follow/{user_id}', 'Follow a user', true],
            ['DELETE', '/follow/{user_id}', 'Unfollow a user', true],
        ],
        'Posts' => [
            ['POST', '/posts', 'Create a post', true],
            ['GET', '/posts/{id}', 'Get a post', true],
            ['DELETE', '/posts/{id}', 'Delete your post', true],
        ],
        'Feed' => [
            ['GET', '/feed', 'Posts from people you follow', true],
        ],
        'Likes' => [
            ['POST', '/posts/{id}/like', 'Like a post', true],
            ['DELETE', '/posts/{id}/like', 'Unlike a post', true],
        ],
    ];
    $base = url('/api');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PulseFeed API</title>
    <meta name="description" content="A news feed API built with Laravel, MySQL and Redis.">
    <style>
        :root {
            --bg: #fafaf9;
            --surface: #ffffff;
            --surface-2: #f4f4f2;
            --border: #e7e5e1;
            --text: #1c1b19;
            --muted: #6b6862;
            --accent: #4f46e5;
            --accent-soft: #eef0ff;
            --get: #0f7b4b;
            --get-bg: #e3f5ec;
            --post: #1d5fd1;
            --post-bg: #e5eefc;
            --delete: #b4232c;
            --delete-bg: #fbe8e9;
            --code-bg: #1c1b19;
            --code-text: #ecebe8;
            --code-muted: #8f8b84;
            --code-accent: #a5b4fc;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111110;
                --surface: #191918;
                --surface-2: #1f1f1d;
                --border: #2b2a28;
                --text: #ecebe8;
                --muted: #9b978f;
                --accent: #8b8cf8;
                --accent-soft: #1f2040;
                --get: #4ccf8e;
                --get-bg: #12291e;
                --post: #79a7ff;
                --post-bg: #14223a;
                --delete: #ff8088;
                --delete-bg: #36161a;
                --code-bg: #0b0b0a;
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }
        code, pre { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }

        .container { max-width: 960px; margin: 0 auto; padding: 0 20px; }

        header {
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 60px;
            gap: 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 650;
            text-decoration: none;
        }

        .mark {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            background: var(--accent);
            display: grid;
            place-items: center;
        }

        .nav-links { display: flex; gap: 20px; font-size: 14px; }
        .nav-links a { color: var(--muted); text-decoration: none; }
        .nav-links a:hover { color: var(--text); }

        .hero { padding-block: 64px 40px; }

        .eyebrow {
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            letter-spacing: .02em;
            margin-bottom: 12px;
        }

        h1 {
            font-size: clamp(30px, 5vw, 42px);
            line-height: 1.15;
            letter-spacing: -.02em;
            font-weight: 700;
            max-width: 620px;
        }

        .lead {
            margin-top: 16px;
            color: var(--muted);
            font-size: 17px;
            max-width: 600px;
        }

        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }

        .btn {
            display: inline-flex;
            align-items: center;
            height: 40px;
            padding: 0 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border);
            background: var(--surface);
        }

        .btn:hover { background: var(--surface-2); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent); opacity: .9; }

        section { padding-block: 36px; }

        h2 { font-size: 20px; letter-spacing: -.01em; margin-bottom: 6px; }
        .section-lead { color: var(--muted); margin-bottom: 20px; }

        pre {
            background: var(--code-bg);
            color: var(--code-text);
            border-radius: 10px;
            padding: 18px 20px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.7;
        }

        pre .c { color: var(--code-muted); }
        pre .k { color: var(--code-accent); }

        .endpoints {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .group-title {
            padding: 10px 18px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
            background: var(--surface-2);
            border-top: 1px solid var(--border);
        }

        .group-title:first-child { border-top: 0; }

        .row {
            display: grid;
            grid-template-columns: 76px minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            padding: 11px 18px;
            border-top: 1px solid var(--border);
            font-size: 14px;
        }

        .group-title + .row { border-top: 0; }

        .method {
            font: 600 11px/1 ui-monospace, Menlo, monospace;
            padding: 5px 0;
            border-radius: 5px;
            text-align: center;
        }

        .GET { color: var(--get); background: var(--get-bg); }
        .POST { color: var(--post); background: var(--post-bg); }
        .DELETE { color: var(--delete); background: var(--delete-bg); }

        .path code { font-size: 13px; }
        .path span { display: block; color: var(--muted); font-size: 13px; }

        .auth { font-size: 12px; color: var(--muted); white-space: nowrap; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
        }

        .card h3 { font-size: 15px; margin-bottom: 6px; }
        .card p { color: var(--muted); font-size: 14px; }

        .stack { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }

        .chip {
            font-size: 13px;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-weight: 500;
        }

        footer {
            border-top: 1px solid var(--border);
            margin-top: 24px;
            padding: 24px 0 40px;
            color: var(--muted);
            font-size: 13px;
        }

        footer .container { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; }
        footer a { color: var(--muted); }

        @media (max-width: 600px) {
            .nav-links .hide-sm { display: none; }
            .hero { padding-block: 44px 28px; }
            .row { grid-template-columns: 64px minmax(0, 1fr); }
            .auth { display: none; }
        }
    </style>
</head>
<body>
<header>
    <div class="container nav">
        <a href="/" class="brand">
            <span class="mark" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
            </span>
            PulseFeed
        </a>
        <nav class="nav-links">
            <a href="/docs/api">API reference</a>
            <a href="/docs/api.json" class="hide-sm">OpenAPI</a>
            <a href="https://github.com/MahmoudAbdElWahab96/PulseFeed">GitHub</a>
        </nav>
    </div>
</header>

<main>
    <div class="container hero">
        <p class="eyebrow">REST API · v1</p>
        <h1>A news feed backend built for high read traffic</h1>
        <p class="lead">
            Follow people, publish posts, like them, and read a personalized feed.
            Cursor pagination, Redis caching and queued notifications keep it fast as the data grows.
        </p>
        <div class="actions">
            <a href="/docs/api" class="btn btn-primary">Open API reference</a>
            <a href="/docs/api.json" class="btn">Download OpenAPI spec</a>
        </div>
    </div>

    <section class="container">
        <h2>Quick start</h2>
        <p class="section-lead">Register to get a token, then send it as a bearer token.</p>
<pre><span class="c"># 1. Create an account and copy the token from the response</span>
curl -X POST {{ $base }}/register \
  -H <span class="k">'Content-Type: application/json'</span> -H <span class="k">'Accept: application/json'</span> \
  -d <span class="k">'{"name":"Jane","email":"jane@example.com","password":"secret123"}'</span>

<span class="c"># 2. Follow someone and read your feed</span>
curl -X POST {{ $base }}/follow/2 -H <span class="k">"Authorization: Bearer $TOKEN"</span> -H <span class="k">'Accept: application/json'</span>
curl "{{ $base }}/feed?limit=20" -H <span class="k">"Authorization: Bearer $TOKEN"</span> -H <span class="k">'Accept: application/json'</span></pre>
    </section>

    <section class="container">
        <h2>Endpoints</h2>
        <p class="section-lead">All paths are relative to <code>{{ $base }}</code>.</p>
        <div class="endpoints">
            @foreach ($endpoints as $group => $rows)
                <div class="group-title">{{ $group }}</div>
                @foreach ($rows as [$method, $path, $label, $auth])
                    <div class="row">
                        <span class="method {{ $method }}">{{ $method }}</span>
                        <div class="path"><code>{{ $path }}</code><span>{{ $label }}</span></div>
                        <span class="auth">{{ $auth ? 'Bearer token' : 'Public' }}</span>
                    </div>
                @endforeach
            @endforeach
        </div>
    </section>

    <section class="container">
        <h2>How it stays fast</h2>
        <p class="section-lead">The design choices behind the feed.</p>
        <div class="grid">
            <div class="card">
                <h3>Cursor pagination</h3>
                <p>Pages are index range scans on <code>(created_at, id)</code>, so page 1,000 costs the same as page 1.</p>
            </div>
            <div class="card">
                <h3>Fixed query budget</h3>
                <p>Authors, like counts and <code>liked_by_me</code> load in batches: no N+1, whatever the page holds.</p>
            </div>
            <div class="card">
                <h3>Redis caching</h3>
                <p>Feed pages and like counts are cached, with versioned keys invalidated on follow and unfollow.</p>
            </div>
            <div class="card">
                <h3>Queued fan-out</h3>
                <p>New posts notify followers in chunked, idempotent jobs, so the request never waits.</p>
            </div>
        </div>
        <div class="stack">
            <span class="chip">Laravel 13</span>
            <span class="chip">PHP 8.4</span>
            <span class="chip">MySQL 8.4</span>
            <span class="chip">Redis</span>
            <span class="chip">Sanctum</span>
            <span class="chip">Docker</span>
        </div>
    </section>
</main>

<footer>
    <div class="container">
        <span>PulseFeed</span>
        <span><a href="/up">Health check</a> · <a href="/docs/api">API reference</a></span>
    </div>
</footer>
</body>
</html>
