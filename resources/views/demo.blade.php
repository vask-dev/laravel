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
    --accent-client: #c084fc;
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
  .emojis { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .emojis button { font-size: 28px; line-height: 1; padding: 10px 14px; background: var(--card); border: 1px solid var(--border); border-radius: 10px; cursor: pointer; color: inherit; transition: transform 0.05s ease, border-color 0.15s ease; }
  .emojis button:hover { border-color: var(--accent); }
  .emojis button[data-mode="client"]:hover { border-color: var(--accent-client); }
  .emojis button:active { transform: scale(0.94); }
  .emojis button:disabled { opacity: 0.4; cursor: not-allowed; }
  .emojis .divider { width: 1px; align-self: stretch; background: var(--border); margin: 0 6px; }
  .mode-tag { display: inline-block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; padding: 2px 6px; border-radius: 4px; vertical-align: middle; margin-left: 6px; }
  .mode-tag.server { background: rgba(96, 165, 250, 0.15); color: var(--accent); }
  .mode-tag.client { background: rgba(192, 132, 252, 0.15); color: var(--accent-client); }
  #stage { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 5; }
  .floater { position: absolute; bottom: -64px; font-size: 36px; animation: rise 2.6s linear forwards; will-change: transform, opacity; }
  @keyframes rise {
    0%   { transform: translateY(0)     scale(1);    opacity: 0; }
    10%  { transform: translateY(-40px) scale(1.1);  opacity: 1; }
    100% { transform: translateY(-90vh) scale(0.85); opacity: 0; }
  }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px; }
  .stat { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
  .stat .label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
  .stat .value { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 18px; margin-top: 2px; }
  .stat .sublabel { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted); font-size: 11px; margin-top: 2px; }
  .hint { color: var(--muted); font-size: 12px; margin-top: 10px; line-height: 1.5; }
  .hint strong { color: var(--fg); font-weight: 500; }
  .hint code { background: rgba(255,255,255,0.05); padding: 1px 5px; border-radius: 3px; font-size: 11px; }
  .log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; max-height: 220px; overflow: auto; background: #0a0c0f; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
  .log .line { padding: 1px 0; color: var(--muted); }
  .log .line.me { color: var(--fg); }
  .log .line .lat { color: var(--accent); }
  .log .line .lat.client { color: var(--accent-client); }
  .banner { background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
  @media (max-width: 640px) {
    .stats { grid-template-columns: repeat(2, 1fr); }
  }
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
      @foreach (['🎉','🚀','🔥','❤️'] as $e)
        <button data-emoji="{{ $e }}" data-mode="server" title="Send via server (POST → broadcast)" {{ $configured ? '' : 'disabled' }}>{{ $e }}</button>
      @endforeach
      <span class="divider" aria-hidden="true"></span>
      @foreach (['😂','👀','✨','🐙'] as $e)
        <button data-emoji="{{ $e }}" data-mode="client" title="Send as Pusher client event (browser → Vask → other subscribers)" {{ $configured ? '' : 'disabled' }}>{{ $e }}</button>
      @endforeach
    </div>
    <div class="stats">
      <div class="stat">
        <div class="label"><span class="mode-tag server">server</span> WS receive</div>
        <div class="value" id="last-rt">—</div>
        <div class="sublabel" id="avg-rt">avg —</div>
      </div>
      <div class="stat">
        <div class="label"><span class="mode-tag server">server</span> POST</div>
        <div class="value" id="last-post">—</div>
        <div class="sublabel" id="avg-post">avg —</div>
      </div>
      <div class="stat">
        <div class="label"><span class="mode-tag client">client</span> arrival</div>
        <div class="value" id="last-client">—</div>
        <div class="sublabel" id="avg-client">avg —</div>
      </div>
      <div class="stat">
        <div class="label">Received</div>
        <div class="value" id="count">0</div>
        <div class="sublabel">events</div>
      </div>
    </div>
    <div class="hint">
      <strong>Server</strong> emojis POST to Laravel, which calls <code>broadcast()</code> — measures the full Laravel → Vask HTTP API → WS path.
      <strong>Client</strong> emojis fire a Pusher client event straight from the browser, bypassing Laravel.
      Pusher doesn't echo client events back to the sender, so the <em>client arrival</em> stat only fills in for events from
      <em>other</em> subscribers — open this page in a second tab to see it light up.
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
    clientEventName: @json($clientEventName),
    broadcastUrl: @json(route('vask.demo.broadcast')),
    authUrl: @json(route('vask.demo.auth')),
  };

  const senderId = 's-' + Math.random().toString(36).slice(2, 10);
  const $status = document.getElementById('status');
  const $dot = document.getElementById('dot');
  const $log = document.getElementById('log');
  const $count = document.getElementById('count');
  const $lastRt = document.getElementById('last-rt');
  const $avgRt = document.getElementById('avg-rt');
  const $lastPost = document.getElementById('last-post');
  const $avgPost = document.getElementById('avg-post');
  const $lastClient = document.getElementById('last-client');
  const $avgClient = document.getElementById('avg-client');
  const $stage = document.getElementById('stage');

  // Use absolute wall-clock time (sub-ms precision) so latencies are
  // computable across tabs / machines, not just within the same page.
  const now = () => performance.timeOrigin + performance.now();

  // sentAt is the correlation key between a click and the broadcast we get
  // back over the WebSocket. For server-broadcast emojis we stash the POST
  // round-trip per click keyed by sentAt so when the WS message arrives we
  // can show both legs side-by-side.
  const postTimes = new Map();
  const rtLatencies = [];
  const postLatencies = [];
  const clientLatencies = [];
  let received = 0;

  function rollingAvg(arr) {
    if (arr.length === 0) return null;
    return arr.reduce((a, b) => a + b, 0) / arr.length;
  }

  function pushLat(arr, value) {
    arr.push(value);
    if (arr.length > 10) arr.shift();
  }

  function fmt(ms) {
    return ms.toFixed(0) + ' ms';
  }

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

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c]));
  }

  const pusher = new Pusher(cfg.key, {
    wsHost: cfg.wsHost,
    wssPort: cfg.port,
    wsPort: cfg.port,
    forceTLS: cfg.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    cluster: cfg.cluster,
    disableStats: true,
    channelAuthorization: { endpoint: cfg.authUrl, transport: 'ajax' },
  });

  // Vask/Pusher error codes that we want to surface as a clean, one-line
  // message instead of dumping the raw JSON. 4009 in particular fires for
  // *every* client event the server rejects, so without suppression the log
  // turns into wallpaper.
  let clientEventsBlocked = false;
  const $clientButtons = document.querySelectorAll('button[data-mode="client"]');

  function blockClientEvents(reason) {
    if (clientEventsBlocked) return;
    clientEventsBlocked = true;
    $clientButtons.forEach((b) => { b.disabled = true; b.title = reason; });
    logLine('<span style="color:var(--bad)">client events disabled:</span> ' + escapeHtml(reason), false);
  }

  pusher.connection.bind('state_change', (s) => setStatus(s.current));
  pusher.connection.bind('error', (err) => {
    const code = (err && err.data && err.data.code) || (err && err.error && err.error.data && err.error.data.code);
    if (code === 4009) {
      blockClientEvents('Client events are not enabled for this Vask app — enable them in your Vask dashboard, then reload.');
      return;
    }
    logLine('<span style="color:var(--bad)">connection error:</span> ' + escapeHtml(JSON.stringify(err)), false);
  });
  setStatus(pusher.connection.state);

  const ch = pusher.subscribe(cfg.channel);
  ch.bind('pusher:subscription_succeeded', () => logLine('subscribed to <strong>' + cfg.channel + '</strong>', false));
  ch.bind('pusher:subscription_error', (status) => logLine('<span style="color:var(--bad)">subscription error:</span> ' + JSON.stringify(status), false));

  // Server-broadcast events (sender does receive their own).
  ch.bind(cfg.eventName, (data) => {
    received++;
    $count.textContent = String(received);
    floatEmoji(data.emoji, typeof data.x === 'number' ? data.x : Math.random());

    const mine = data.senderId === senderId;
    if (mine && typeof data.sentAt === 'number') {
      const rt = now() - data.sentAt;
      $lastRt.textContent = fmt(rt);
      pushLat(rtLatencies, rt);
      $avgRt.textContent = 'avg ' + fmt(rollingAvg(rtLatencies));

      const postT = postTimes.get(data.sentAt);
      const postStr = postT !== undefined ? ' &nbsp; POST <span class="lat">' + fmt(postT) + '</span>' : '';
      logLine('<span class="mode-tag server">srv</span> ' + data.emoji + ' &nbsp; WS <span class="lat">' + fmt(rt) + '</span>' + postStr, true);
    } else if (typeof data.sentAt === 'number') {
      // Server broadcast from another subscriber (cross-tab) — measure
      // one-way arrival latency using absolute time.
      const lat = now() - data.sentAt;
      logLine('<span class="mode-tag server">srv</span> ' + data.emoji + ' from <code>' + escapeHtml(String(data.senderId || '?')) + '</code> <span class="lat">' + fmt(lat) + '</span>', false);
    } else {
      logLine('<span class="mode-tag server">srv</span> ' + data.emoji + ' from <code>' + escapeHtml(String(data.senderId || '?')) + '</code>', false);
    }
  });

  // Client events. Pusher does NOT echo to the sender, so this only fires
  // for events from other subscribers (another tab, another machine).
  ch.bind(cfg.clientEventName, (data) => {
    received++;
    $count.textContent = String(received);
    floatEmoji(data.emoji, typeof data.x === 'number' ? data.x : Math.random());

    if (typeof data.sentAt === 'number') {
      const lat = now() - data.sentAt;
      $lastClient.textContent = fmt(lat);
      pushLat(clientLatencies, lat);
      $avgClient.textContent = 'avg ' + fmt(rollingAvg(clientLatencies));
      logLine('<span class="mode-tag client">cli</span> ' + data.emoji + ' from <code>' + escapeHtml(String(data.senderId || '?')) + '</code> <span class="lat client">' + fmt(lat) + '</span>', false);
    } else {
      logLine('<span class="mode-tag client">cli</span> ' + data.emoji + ' from <code>' + escapeHtml(String(data.senderId || '?')) + '</code>', false);
    }
  });

  async function sendServer(emoji) {
    const t0 = now();
    const payload = { emoji, x: Math.random(), senderId, sentAt: t0 };
    const t0Local = performance.now();
    try {
      const res = await fetch(cfg.broadcastUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      });
      const postRt = performance.now() - t0Local;
      postTimes.set(t0, postRt);
      if (postTimes.size > 200) {
        const firstKey = postTimes.keys().next().value;
        postTimes.delete(firstKey);
      }
      $lastPost.textContent = fmt(postRt);
      pushLat(postLatencies, postRt);
      $avgPost.textContent = 'avg ' + fmt(rollingAvg(postLatencies));
      if (!res.ok) {
        logLine('<span style="color:var(--bad)">POST failed:</span> ' + res.status + ' ' + res.statusText, false);
      }
    } catch (err) {
      logLine('<span style="color:var(--bad)">POST error:</span> ' + escapeHtml(String(err)), false);
    }
  }

  function sendClient(emoji) {
    if (clientEventsBlocked) return;
    const payload = { emoji, x: Math.random(), senderId, sentAt: now() };
    // Render locally so the sender still sees their own emoji — Pusher
    // won't echo the client event back to us.
    floatEmoji(emoji, payload.x);
    // trigger() returns true if the frame was sent over the wire — it does
    // NOT mean the server accepted it. Rejection (e.g. client events
    // disabled) comes back asynchronously via pusher.connection 'error' and
    // is handled by blockClientEvents().
    const ok = ch.trigger(cfg.clientEventName, payload);
    if (ok) {
      logLine('<span class="mode-tag client">cli</span> ' + emoji + ' sent (no echo — open a second tab to see arrival)', true);
    } else {
      logLine('<span style="color:var(--bad)">client event failed</span> — channel may not be subscribed yet', false);
    }
  }

  document.getElementById('emojis').addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-emoji]');
    if (!btn) return;
    const emoji = btn.dataset.emoji;
    const mode = btn.dataset.mode;
    if (mode === 'client') {
      sendClient(emoji);
    } else {
      sendServer(emoji);
    }
  });
})();
</script>
@endif
</body>
</html>
