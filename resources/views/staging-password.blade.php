<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Private test site</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f7f9; --card: #fff; --text: #17264d; --muted: #5b6478; --line: #d6dae3; --accent: #17264d; --error: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c1220; --card: #141c2e; --text: #eef1f7; --muted: #a3acbf; --line: #2a3550; --accent: #d4a537; --error: #f97066; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, sans-serif; }
        main { width: 100%; max-width: 360px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        p { margin: 0 0 20px; color: var(--muted); font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
        input { width: 100%; padding: 10px 12px; margin: 0 0 16px; border: 1px solid var(--line); border-radius: 8px; background: transparent; color: inherit; font: inherit; }
        input[readonly] { color: var(--muted); }
        button { width: 100%; padding: 10px 12px; border: 0; border-radius: 8px; background: var(--accent); color: var(--card); font: inherit; font-weight: 600; cursor: pointer; }
        .error { color: var(--error); font-size: 14px; margin: -8px 0 16px; }
    </style>
</head>
<body>
<main>
    <h1>Private test site</h1>
    <p>This is Tracing Board's staging site. Enter the staging password to continue.</p>
    <form method="POST" action="/staging-password">
        <input type="hidden" name="back" value="{{ $back }}">
        <label for="username">Username</label>
        <input id="username" name="username" value="{{ $user }}" autocomplete="username" readonly>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        @if ($tooMany)
            <div class="error" role="alert">Too many tries. Wait a minute and try again.</div>
        @elseif ($wrong)
            <div class="error" role="alert">That password is not right.</div>
        @endif
        <button type="submit">Continue</button>
    </form>
</main>
</body>
</html>
