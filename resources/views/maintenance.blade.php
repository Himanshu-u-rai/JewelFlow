<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Down for Maintenance — JewelFlow</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; background: #0b1020; color: #e2e8f0; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; padding: 24px; }
        .card { text-align: center; max-width: 480px; }
        .icon { font-size: 4rem; margin-bottom: 1.5rem; }
        h1 { font-size: 1.5rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.75rem; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.6; }
        .brand { margin-top: 2rem; font-size: 0.75rem; color: #475569; letter-spacing: 0.1em; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔧</div>
        <h1>Down for Maintenance</h1>
        <p>{{ $message }}</p>
        <p class="brand">JewelFlow</p>
    </div>
</body>
</html>
