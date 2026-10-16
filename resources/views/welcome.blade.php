<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'PulseFeed') }} — News Feed API</title>

        <style>
            :root {
                --bg: #0b0f17;
                --panel: #121826;
                --panel-2: #161e2f;
                --border: #232d42;
                --text: #e7ecf5;
                --muted: #94a3b8;
                --accent: #f97362;
                --accent-2: #fba998;
                --green: #4ade80;
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }

            body {
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                background: var(--bg);
                color: var(--text);
                min-height: 100vh;
                -webkit-font-smoothing: antialiased;
            }

            .glow {
                position: fixed;
                top: -220px;
                left: 50%;
                transform: translateX(-50%);
                width: 720px;
                height: 480px;
                background: radial-gradient(ellipse at center, rgba(249, 115, 98, .16), transparent 65%);
                pointer-events: none;
            }

            .wrap {
                max-width: 880px;
                margin: 0 auto;
                padding: 72px 24px 64px;
                position: relative;
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                color: var(--muted);
                border: 1px solid var(--border);
                background: var(--panel);
                padding: 6px 14px;
                border-radius: 999px;
            }

            .badge .dot {
                width: 8px; height: 8px; border-radius: 50%;
                background: var(--green);
                box-shadow: 0 0 10px var(--green);
            }

            h1 {
                font-size: clamp(40px, 7vw, 64px);
                letter-spacing: -0.03em;
                line-height: 1.05;
                margin: 28px 0 16px;
            }

            h1 span {
                background: linear-gradient(120deg, var(--accent), var(--accent-2));
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
            }

            .lead {
                color: var(--muted);
                font-size: 18px;
                line-height: 1.6;
                max-width: 560px;
            }

            .actions {
                display: flex;
                flex-wrap: wrap;
                gap: 14px;
                margin: 36px 0 56px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 13px 24px;
                border-radius: 10px;
                font-size: 15px;
                font-weight: 600;
                text-decoration: none;
                transition: transform .15s ease, box-shadow .15s ease;
            }

            .btn-primary {
                background: linear-gradient(120deg, var(--accent), #f0664f);
                color: #fff;
                box-shadow: 0 8px 28px rgba(249, 115, 98, .32);
            }

            .btn-primary:hover { transform: translateY(-2px); }

            .btn-ghost {
                color: var(--text);
                border: 1px solid var(--border);
                background: var(--panel);
            }

            .btn-ghost:hover { border-color: #38466b; }

            .grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 16px;
            }

            .card {
                background: linear-gradient(180deg, var(--panel-2), var(--panel));
                border: 1px solid var(--border);
                border-radius: 14px;
                padding: 22px;
            }

            .card h3 {
                font-size: 15px;
                margin-bottom: 8px;
            }

            .card p {
                color: var(--muted);
                font-size: 14px;
                line-height: 1.55;
            }

            .card code {
                color: var(--accent-2);
                font-size: 13px;
            }

            footer {
                margin-top: 56px;
                padding-top: 24px;
                border-top: 1px solid var(--border);
                color: var(--muted);
                font-size: 13px;
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 10px;
            }

            footer a { color: var(--muted); text-decoration: none; }
            footer a:hover { color: var(--text); }
        </style>
    </head>
    <body>
        <div class="glow"></div>

        <main class="wrap">
            <span class="badge"><span class="dot"></span> API online</span>

            <h1>The news feed <span>API</span>, ready to explore.</h1>

            <p class="lead">
                A high-traffic feed backend: accounts, follows, posts, a cursor-paginated
                feed, likes with cached counts, and asynchronous follower notifications —
                all behind token authentication.
            </p>

            <div class="actions">
                <a class="btn btn-primary" href="/docs/api">
                    API Documentation
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
                <a class="btn btn-ghost" href="/docs/api.json">OpenAPI 3.1 spec (JSON)</a>
            </div>

            <div class="grid">
                <div class="card">
                    <h3>Feed</h3>
                    <p>Newest-first from followed users, deterministic ordering, cursor pagination with bounded page sizes.</p>
                </div>
                <div class="card">
                    <h3>Likes</h3>
                    <p><code>likes_count</code> and per-user <code>liked_by_me</code>, computed in batched queries and cached in Redis.</p>
                </div>
                <div class="card">
                    <h3>Notifications</h3>
                    <p>Follower fan-out through chunked, idempotent queue jobs — retried safely, never duplicated.</p>
                </div>
                <div class="card">
                    <h3>Auth</h3>
                    <p>Sanctum personal access tokens. Throttled login with responses that never reveal whether an email exists.</p>
                </div>
            </div>

            <footer>
                <span>{{ config('app.name', 'PulseFeed') }} · Laravel · MySQL · Redis</span>
                <span>Base URL: <code>/api</code> &nbsp;·&nbsp; Health: <a href="/up">/up</a></span>
            </footer>
        </main>
    </body>
</html>
