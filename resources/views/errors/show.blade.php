@php
    $signedIn = $signedIn ?? false;
    $showLogout = $showLogout ?? false;
    $badge = match ((int) $status) {
        403, 405, 419, 429, 503 => 'amber',
        404 => 'cyan',
        default => 'rose',
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} — {{ config('app.name', 'MailDesk') }}</title>
        <style>
            :root { color-scheme: dark; }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                background: #09090b;
                font-family: Inter, system-ui, -apple-system, sans-serif;
                color: #fff;
            }
            .card {
                width: 100%;
                max-width: 28rem;
                padding: 2rem;
                text-align: center;
                border: 1px solid rgba(39, 39, 42, 0.8);
                border-radius: 0.75rem;
                background: #09090b;
            }
            .badge {
                width: 3rem;
                height: 3rem;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 9999px;
            }
            .badge.amber { background: rgba(245, 158, 11, 0.1); color: #fcd34d; }
            .badge.cyan { background: rgba(34, 211, 238, 0.1); color: #67e8f9; }
            .badge.rose { background: rgba(244, 63, 94, 0.1); color: #fda4af; }
            .code { margin: 1rem 0 0; font-size: 0.875rem; font-weight: 500; color: #67e8f9; }
            h1 { margin: 0.25rem 0 0; font-size: 1.125rem; font-weight: 600; }
            p { margin: 0.75rem 0 0; font-size: 0.875rem; line-height: 1.6; color: #a1a1aa; }
            .actions { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: center; }
            a, button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.5rem 0.875rem;
                border-radius: 9999px;
                font-size: 0.875rem;
                font-weight: 500;
                font-family: inherit;
                text-decoration: none;
                cursor: pointer;
            }
            .ghost {
                border: 1px solid #27272a;
                background: transparent;
                color: #d4d4d8;
            }
            .ghost:hover { background: #18181b; color: #fff; }
            .primary {
                border: 0;
                background: #22d3ee;
                color: #09090b;
            }
            .primary:hover { background: #67e8f9; }
        </style>
    </head>
    <body>
        <main class="card">
            <div class="badge {{ $badge }}" aria-hidden="true">!</div>
            <p class="code">{{ $status }}</p>
            <h1>{{ $title }}</h1>
            <p>{{ $message }}</p>
            <div class="actions">
                @if ($showLogout)
                    <a class="ghost" href="{{ $profileUrl ?? url('/profile') }}">Profile</a>
                    <form method="POST" action="{{ $logoutUrl ?? url('/logout') }}">
                        @csrf
                        <button type="submit" class="primary">Log out</button>
                    </form>
                @else
                    <a class="ghost" href="javascript:history.back()">Go back</a>
                    <a class="primary" href="{{ $homeUrl }}">{{ $homeLabel }}</a>
                @endif
            </div>
        </main>
    </body>
</html>
