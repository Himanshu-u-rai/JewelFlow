<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title>JewelFlow Scanner</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: #0f172a;
            color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            min-height: 100svh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
        }

        /* ─── Header ─── */
        .scanner-header {
            background: rgba(255,255,255,0.05);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            backdrop-filter: blur(10px);
        }
        .scanner-logo {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.04em;
        }
        .scanner-logo span { color: #fca311; }
        .scanner-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(22, 163, 74, 0.2);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.3);
            transition: all 0.3s;
        }
        .scanner-badge.expired {
            background: rgba(220, 38, 38, 0.2);
            color: #f87171;
            border-color: rgba(248, 113, 113, 0.3);
        }
        .scanner-badge.sending {
            background: rgba(234, 179, 8, 0.2);
            color: #facc15;
            border-color: rgba(250, 204, 21, 0.3);
        }

        /* ─── Camera area ─── */
        .scanner-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            min-height: 0;
        }

        #qr-reader {
            width: 100%;
            height: 100%;
            position: absolute;
            inset: 0;
        }
        #qr-reader > div { height: 100% !important; }
        /* Override html5-qrcode default styles */
        #qr-reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }
        #qr-reader img { display: none !important; }
        #qr-reader__scan_region { background: transparent !important; }
        #qr-reader__dashboard { display: none !important; }

        /* ─── Scanning overlay frame ─── */
        .scan-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 10;
        }
        .scan-frame {
            width: min(70vw, 280px);
            height: min(70vw, 280px);
            position: relative;
        }
        .scan-frame::before, .scan-frame::after,
        .scan-frame span::before, .scan-frame span::after {
            content: '';
            position: absolute;
            width: 28px;
            height: 28px;
            border-color: #fca311;
            border-style: solid;
        }
        .scan-frame::before { top: 0; left: 0; border-width: 3px 0 0 3px; border-radius: 4px 0 0 0; }
        .scan-frame::after  { top: 0; right: 0; border-width: 3px 3px 0 0; border-radius: 0 4px 0 0; }
        .scan-frame span::before { bottom: 0; left: 0; border-width: 0 0 3px 3px; border-radius: 0 0 0 4px; }
        .scan-frame span::after  { bottom: 0; right: 0; border-width: 0 3px 3px 0; border-radius: 0 0 4px 0; }

        .scan-line {
            position: absolute;
            left: 4%;
            right: 4%;
            height: 2px;
            background: linear-gradient(90deg, transparent, #fca311, transparent);
            animation: scanline 2s ease-in-out infinite;
            top: 0;
        }
        @keyframes scanline {
            0%   { top: 5%; opacity: 1; }
            90%  { top: 95%; opacity: 1; }
            100% { top: 5%; opacity: 0; }
        }

        /* ─── Bottom status bar ─── */
        .scanner-footer {
            background: rgba(15, 23, 42, 0.95);
            border-top: 1px solid rgba(255,255,255,0.08);
            padding: 16px 20px 24px;
            flex-shrink: 0;
            backdrop-filter: blur(10px);
        }
        .scan-status-text {
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            text-align: center;
            margin-bottom: 10px;
            min-height: 20px;
            transition: color 0.3s;
        }
        .scan-status-text.success { color: #4ade80; }
        .scan-status-text.error   { color: #f87171; }
        .scan-history {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 100px;
            overflow-y: auto;
        }
        .scan-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 10px;
            background: rgba(255,255,255,0.06);
            border-radius: 6px;
            font-size: 12px;
            animation: fadeSlide 0.3s ease;
        }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .scan-item-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #4ade80;
            flex-shrink: 0;
        }
        .scan-item-barcode { color: #e2e8f0; font-weight: 600; flex: 1; }
        .scan-item-time    { color: #64748b; font-size: 11px; }

        /* ─── Expired overlay ─── */
        .expired-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.96);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            z-index: 50;
            text-align: center;
            padding: 40px;
        }
        .expired-icon { font-size: 48px; }
        .expired-title { font-size: 18px; font-weight: 700; color: #f87171; }
        .expired-msg { font-size: 13px; color: #94a3b8; line-height: 1.6; }

        /* ─── Permission prompt ─── */
        #permissionPrompt {
            position: absolute;
            inset: 0;
            background: #0f172a;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            z-index: 40;
            text-align: center;
            padding: 40px;
        }
        .permission-icon { font-size: 52px; }
        .permission-title { font-size: 18px; font-weight: 700; }
        .permission-msg { font-size: 13px; color: #94a3b8; line-height: 1.6; }
        .btn-allow {
            padding: 14px 32px;
            background: #fca311;
            color: #0f172a;
            font-weight: 800;
            font-size: 15px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    @if($expired)
        <!-- Session already expired when page loaded -->
        <div class="scanner-header">
            <div class="scanner-logo">Jewel<span>Flow</span></div>
            <div class="scanner-badge expired">Session Expired</div>
        </div>
        <div class="scanner-body">
            <div class="expired-overlay" style="position:relative; flex:1;">
                <div class="expired-icon">⏱️</div>
                <div class="expired-title">Session Expired</div>
                <div class="expired-msg">This scan session has expired.<br>Ask your cashier to start a new scan session from the POS.</div>
            </div>
        </div>
    @else
        <!-- Active session — show scanner -->
        <div class="scanner-header">
            <div class="scanner-logo">Jewel<span>Flow</span></div>
            <div class="scanner-badge" id="statusBadge">● Ready</div>
        </div>

        <div class="scanner-body">
            <!-- Camera will render here -->
            <div id="qr-reader"></div>

            <!-- Corner frame overlay -->
            <div class="scan-overlay">
                <div class="scan-frame">
                    <span></span>
                    <div class="scan-line"></div>
                </div>
            </div>

            <!-- Permission prompt shown before camera starts -->
            <div id="permissionPrompt">
                <div class="permission-icon">📷</div>
                <div class="permission-title">Camera Access Needed</div>
                <div class="permission-msg">JewelFlow needs camera access to scan barcodes. Your camera is only used for scanning — nothing is saved.</div>
                <button class="btn-allow" onclick="startCamera()">Allow Camera & Start Scanning</button>
            </div>

            <!-- Shown when session expires while scanning -->
            <div class="expired-overlay" id="expiredOverlay" style="display:none;">
                <div class="expired-icon">⏱️</div>
                <div class="expired-title">Session Expired</div>
                <div class="expired-msg">This scan session has timed out.<br>Ask your cashier to start a new session.</div>
            </div>
        </div>

        <div class="scanner-footer">
            <div class="scan-status-text" id="statusText">Point camera at a barcode to scan</div>
            <div class="scan-history" id="scanHistory"></div>
        </div>
    @endif

    @if(!$expired)
    <!-- html5-qrcode CDN with fallback -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
    if (typeof Html5Qrcode === 'undefined') {
        var s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
        document.head.appendChild(s);
    }
    </script>
    <script>
    (function() {
        const TOKEN       = @json($token);
        const POST_URL    = @json(route('scan.post'));
        const CSRF        = @json(csrf_token());
        const EXPIRY      = new Date(@json($session->expires_at->toIso8601String()));

        let html5QrCode   = null;
        let lastBarcode   = null;
        let lastScanTime  = 0;
        let sessionActive = true;

        // ── Beep using Web Audio API ──────────────────────
        function beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 1400;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0.4, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.18);
            } catch(e) {}
        }

        function vibrate() {
            try { navigator.vibrate && navigator.vibrate([60]); } catch(e) {}
        }

        // ── Status helpers ────────────────────────────────
        function setStatus(text, cls) {
            const el = document.getElementById('statusText');
            const badge = document.getElementById('statusBadge');
            el.textContent = text;
            el.className = 'scan-status-text ' + (cls || '');
            if (cls === 'success') badge.textContent = '✓ Sent';
            else if (cls === 'error') badge.textContent = '● Error';
            else badge.textContent = '● Scanning';
        }

        function addHistory(barcode) {
            const container = document.getElementById('scanHistory');
            const now = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const div = document.createElement('div');
            div.className = 'scan-item';
            div.innerHTML =
                '<div class="scan-item-dot"></div>' +
                '<div class="scan-item-barcode">' + barcode + '</div>' +
                '<div class="scan-item-time">' + now + '</div>';
            container.prepend(div);
            // Keep max 5 items
            while (container.children.length > 5) {
                container.removeChild(container.lastChild);
            }
        }

        // ── Session expiry watchdog ───────────────────────
        function checkExpiry() {
            if (new Date() >= EXPIRY) {
                sessionActive = false;
                if (html5QrCode) html5QrCode.stop().catch(() => {});
                document.getElementById('expiredOverlay').style.display = 'flex';
                document.getElementById('statusBadge').textContent = '● Expired';
                document.getElementById('statusBadge').className = 'scanner-badge expired';
            }
        }
        setInterval(checkExpiry, 5000);

        // ── Debounce: ignore same barcode within 2s ───────
        function isDuplicate(barcode) {
            const now = Date.now();
            if (barcode === lastBarcode && (now - lastScanTime) < 2000) return true;
            lastBarcode  = barcode;
            lastScanTime = now;
            return false;
        }

        // ── Send scan to server ───────────────────────────
        async function sendScan(barcode) {
            if (!sessionActive) return;
            if (isDuplicate(barcode)) return;

            beep();
            vibrate();
            setStatus('Sending: ' + barcode + '…', 'sending');
            document.getElementById('statusBadge').textContent = '⤴ Sending';
            document.getElementById('statusBadge').className = 'scanner-badge sending';

            try {
                const res = await fetch(POST_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ token: TOKEN, barcode }),
                });

                const data = await res.json();

                if (res.status === 410) {
                    sessionActive = false;
                    document.getElementById('expiredOverlay').style.display = 'flex';
                    return;
                }

                if (data.status === 'ok' || data.status === 'debounced') {
                    addHistory(barcode);
                    setStatus('✓ Sent: ' + barcode, 'success');
                    document.getElementById('statusBadge').textContent = '✓ Sent';
                    document.getElementById('statusBadge').className = 'scanner-badge';
                    setTimeout(() => setStatus('Point camera at a barcode to scan'), 2000);
                }

            } catch(err) {
                setStatus('Network error — will retry next scan', 'error');
                setTimeout(() => setStatus('Point camera at a barcode to scan'), 2000);
            }
        }

        // ── Start camera ──────────────────────────────────
        window.startCamera = function() {
            document.getElementById('permissionPrompt').style.display = 'none';
            setStatus('Starting camera…');

            html5QrCode = new Html5Qrcode('qr-reader');

            html5QrCode.start(
                { facingMode: 'environment' },
                {
                    fps: 15,
                    qrbox: { width: 260, height: 260 },
                    aspectRatio: window.innerHeight / window.innerWidth,
                    disableFlip: false,
                },
                (decodedText) => {
                    sendScan(decodedText.trim());
                },
                () => { /* scan failure — silent */ }
            ).then(() => {
                setStatus('Point camera at a barcode to scan');
            }).catch((err) => {
                setStatus('Camera error: ' + err, 'error');
                document.getElementById('permissionPrompt').style.display = 'flex';
                document.getElementById('permissionPrompt').querySelector('.permission-msg').textContent =
                    'Camera access denied. Please allow camera in browser settings and reload.';
                document.getElementById('permissionPrompt').querySelector('.btn-allow').style.display = 'none';
            });
        };

        // Auto-start on mobile (camera permission will be requested by browser)
        // Small delay to let styles render first
        setTimeout(startCamera, 300);

    })();
    </script>
    @endif
</body>
</html>
