<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vask demo</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #0b0d10;
    --fg: #e8eaed;
    --muted: #8a93a0;
    --ok: #4ade80;
    --bad: #f87171;
    --warn: #fbbf24;
    --card: #14181d;
    --border: #232a33;
    --accent: #60a5fa;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: var(--bg); color: var(--fg); font: 14px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 880px; margin: 0 auto; padding: 32px 20px 120px; }
  h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.01em; }
  .sub { color: var(--muted); margin-bottom: 24px; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; }
  .row { display: flex; justify-content: space-between; gap: 16px; align-items: baseline; padding: 4px 0; }
  .row .k { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
  .row .v { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; word-break: break-all; text-align: right; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; vertical-align: middle; margin-right: 8px; background: var(--muted); }
  .dot.ok { background: var(--ok); box-shadow: 0 0 0 4px rgba(74, 222, 128, 0.15); }
  .dot.bad { background: var(--bad); }
  .dot.warn { background: var(--warn); }
  .emojis { display: flex; flex-wrap: wrap; gap: 8px; }
  .emojis button { font-size: 28px; line-height: 1; padding: 10px 14px; background: var(--card); border: 1px solid var(--border); border-radius: 10px; cursor: pointer; color: inherit; transition: transform 0.05s ease, border-color 0.15s ease; }
  .emojis button:hover { border-color: var(--accent); }
  .emojis button:active { transform: scale(0.94); }
  .emojis button:disabled { opacity: 0.4; cursor: not-allowed; }
  #stage { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 5; }
  .floater { position: absolute; bottom: -64px; font-size: 36px; animation: rise 2.6s linear forwards; will-change: transform, opacity; }
  @keyframes rise {
    0%   { transform: translateY(0)     scale(1);    opacity: 0; }
    10%  { transform: translateY(-40px) scale(1.1);  opacity: 1; }
    100% { transform: translateY(-90vh) scale(0.85); opacity: 0; }
  }
  .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 8px; }
  .stat { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
  .stat .label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
  .stat .value { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 18px; margin-top: 2px; }
  .log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; max-height: 200px; overflow: auto; background: #0a0c0f; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
  .log .line { padding: 1px 0; color: var(--muted); }
  .log .line.me { color: var(--fg); }
  .log .line .lat { color: var(--accent); }
  .banner { background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
</style>
</head>
<body>
<div id="stage"></div>
<main>
  <h1>Vask demo</h1>
  <div class="sub">A local-only smoke test for your Vask install. Click an emoji to broadcast.</div>

  @unless($configured)
    <div class="banner">
      <strong>Vask isn't configured yet.</strong> Run <code>php artisan vask:install</code> first.
    </div>
  @endunless

  <div class="card">
    <div class="row"><span class="k">Status</span><span class="v"><span id="dot" class="dot"></span><span id="status">idle</span></span></div>
    <div class="row"><span class="k">Host</span><span class="v">{{ $scheme === 'https' ? 'wss' : 'ws' }}://{{ $host }}:{{ $port }}</span></div>
    <div class="row"><span class="k">App key</span><span class="v">{{ $key !== '' ? $key : '— not set —' }}</span></div>
    <div class="row"><span class="k">Channel</span><span class="v">{{ $channel }}</span></div>
  </div>

  <div class="card">
    <div class="emojis" id="emojis">
      @foreach (['🎉','🚀','🔥','❤️','😂','👀','✨','🐙'] as $e)
        <button data-emoji="{{ $e }}" {{ $configured ? '' : 'disabled' }}>{{ $e }}</button>
      @endforeach
    </div>
    <div class="stats">
      <div class="stat"><div class="label">Last round-trip</div><div class="value" id="last-rt">—</div></div>
      <div class="stat"><div class="label">Avg (mine, last 10)</div><div class="value" id="avg-rt">—</div></div>
      <div class="stat"><div class="label">Received</div><div class="value" id="count">0</div></div>
    </div>
  </div>

  <div class="card">
    <div class="row"><span class="k">Event log</span><span class="v" style="color:var(--muted)">most recent first</span></div>
    <div class="log" id="log"></div>
  </div>
</main>

@if($configured)
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
<script>
(() => {
  const cfg = {
    key: @json($key),
    wsHost: @json($host),
    port: @json($port),
    scheme: @json($scheme),
    cluster: @json($cluster),
    channel: @json($channel),
    eventName: @json($eventName),
    broadcastUrl: @json(route('vask.demo.broadcast')),
  };

  const senderId = 's-' + Math.random().toString(36).slice(2, 10);
  const $status = document.getElementById('status');
  const $dot = document.getElementById('dot');
  const $log = document.getElementById('log');
  const $count = document.getElementById('count');
  const $last = document.getElementById('last-rt');
  const $avg = document.getElementById('avg-rt');
  const $stage = document.getElementById('stage');

  const myLatencies = [];
  let received = 0;

  function setStatus(state) {
    $status.textContent = state;
    $dot.className = 'dot ' + (
      state === 'connected' ? 'ok' :
      state === 'connecting' || state === 'initialized' ? 'warn' :
      state === 'unavailable' || state === 'failed' || state === 'disconnected' ? 'bad' : ''
    );
  }

  function logLine(html, mine) {
    const el = document.createElement('div');
    el.className = 'line' + (mine ? ' me' : '');
    el.innerHTML = html;
    $log.prepend(el);
    while ($log.childElementCount > 100) $log.removeChild($log.lastChild);
  }

  function floatEmoji(emoji, x) {
    const el = document.createElement('div');
    el.className = 'floater';
    el.textContent = emoji;
    el.style.left = (x * 100).toFixed(2) + '%';
    $stage.appendChild(el);
    setTimeout(() => el.remove(), 2700);
  }

  const pusher = new Pusher(cfg.key, {
    wsHost: cfg.wsHost,
    wssPort: cfg.port,
    wsPort: cfg.port,
    forceTLS: cfg.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    cluster: cfg.cluster,
    disableStats: true,
  });

  pusher.connection.bind('state_change', (s) => setStatus(s.current));
  pusher.connection.bind('error', (err) => {
    logLine('<span style="color:var(--bad)">connection error:</span> ' + escapeHtml(JSON.stringify(err)), false);
  });
  setStatus(pusher.connection.state);

  const ch = pusher.subscribe(cfg.channel);
  ch.bind('pusher:subscription_succeeded', () => logLine('subscribed to <strong>' + cfg.channel + '</strong>', false));
  ch.bind('pusher:subscription_error', (status) => logLine('<span style="color:var(--bad)">subscription error:</span> ' + status, false));

  ch.bind(cfg.eventName, (data) => {
    received++;
    $count.textContent = String(received);
    floatEmoji(data.emoji, typeof data.x === 'number' ? data.x : Math.random());

    const mine = data.senderId === senderId;
    if (mine && typeof data.sentAt === 'number') {
      const rt = performance.now() - data.sentAt;
      $last.textContent = rt.toFixed(0) + ' ms';
      myLatencies.push(rt);
      if (myLatencies.length > 10) myLatencies.shift();
      const avg = myLatencies.reduce((a, b) => a + b, 0) / myLatencies.length;
      $avg.textContent = avg.toFixed(0) + ' ms';
      logLine(data.emoji + ' &nbsp; <span class="lat">' + rt.toFixed(0) + ' ms</span> round-trip', true);
    } else {
      logLine(data.emoji + ' from <code>' + escapeHtml(String(data.senderId || '?')) + '</code>', false);
    }
  });

  document.getElementById('emojis').addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-emoji]');
    if (!btn) return;
    const emoji = btn.dataset.emoji;
    const payload = {
      emoji,
      x: Math.random(),
      senderId,
      sentAt: performance.now(),
    };
    try {
      const res = await fetch(cfg.broadcastUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res.ok) {
        logLine('<span style="color:var(--bad)">POST failed:</span> ' + res.status + ' ' + res.statusText, false);
      }
    } catch (err) {
      logLine('<span style="color:var(--bad)">POST error:</span> ' + escapeHtml(String(err)), false);
    }
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c]));
  }
})();
</script>
@endif
</body>
</html>
