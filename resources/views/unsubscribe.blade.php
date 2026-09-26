<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $done ? 'Unsubscribed' : 'Unsubscribe' }} · {{ $organization }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f4f5; color: #18181b; }
        .card { width: min(420px, calc(100% - 32px)); padding: 32px; border-radius: 16px; background: #fff; border: 1px solid #e4e4e7; box-shadow: 0 10px 30px rgba(0,0,0,.06); text-align: center; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        p { margin: 0 0 20px; color: #52525b; font-size: 14px; line-height: 1.5; }
        strong { color: inherit; }
        button, .btn { display: inline-block; border: 0; border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; background: {{ $accent }}; color: #fff; text-decoration: none; }
        button:hover, .btn:hover { filter: brightness(1.1); }
        .footer { margin: 20px 0 0; font-size: 12px; color: #71717a; }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #f4f4f5; }
            .card { background: #18181b; border-color: #27272a; box-shadow: none; }
            p { color: #a1a1aa; }
        }
    </style>
</head>
<body>
    <main class="card">
        @if ($done)
            <h1>{{ $headline }}</h1>
            <p><strong>{{ $email }}</strong> won't receive broadcast emails from {{ $organization }} any more.</p>
            @if ($message)
                <p>{{ $message }}</p>
            @endif
            @if ($preferencesUrl)
                <a class="btn" href="{{ $preferencesUrl }}" rel="noopener">{{ $buttonLabel }}</a>
            @endif
        @else
            <h1>Unsubscribe?</h1>
            <p>Stop broadcast emails from {{ $organization }} to <strong>{{ $email }}</strong>.</p>
            <form method="POST" action="{{ $action }}">
                <button type="submit">Unsubscribe</button>
            </form>
        @endif
        @if ($footer)
            <p class="footer">{{ $footer }}</p>
        @endif
    </main>
</body>
</html>
