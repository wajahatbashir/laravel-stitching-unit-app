<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#0a0a0a;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}
        .c{max-width:340px}h1{font-size:1.4rem;margin:.5rem 0}p{opacity:.85;line-height:1.5}
        a,button{display:inline-block;margin-top:14px;background:#c9a24d;color:#0a0a0a;font-weight:700;border:0;border-radius:12px;padding:12px 20px;text-decoration:none;font-size:1rem}
    </style>
</head>
<body>
<div class="c">
    <img src="/brand/icon-black.png" width="84" height="84" alt="" style="border-radius:50%">
    <h1>You're offline</h1>
    <p>This page isn't saved on your phone yet. Pages you've opened before — and the "Add" forms — still work offline. Anything you save is stored and syncs automatically when the internet is back.</p>
    <a href="/dashboard">Go to dashboard</a>
    <button onclick="location.reload()">Retry</button>
</div>
</body>
</html>
