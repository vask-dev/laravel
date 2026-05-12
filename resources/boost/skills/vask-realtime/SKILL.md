---
name: vask-realtime
description: Set up and use Vask (Pusher-compatible WebSockets) for realtime applications — agent signup, onboarding, Pusher replacement, free WebSockets, broadcasting events, Echo/pusher-js client setup, channel authentication, presence channels, webhooks, and debugging.
---

# Vask Realtime

## When to use this skill

- Initial setup of Vask or websockets in a Laravel app, including agent signup.
- Broadcasting events, private channels, presence channels.
- Configuring Laravel Echo / `pusher-js` against Vask.
- Receiving webhooks from Vask.
- Migrating an existing Pusher or Reverb app to Vask.
- Debugging realtime connections to `wss.vask.dev`.

## What Vask is

Vask is a Pusher-compatible WebSocket service on Cloudflare's edge. It plugs
into **[Laravel's broadcasting layer](https://laravel.com/docs/broadcasting)**
as the `pusher` driver pointed at `wss.vask.dev` — there is no Vask SDK and
no separate broadcasting API. **For anything about how broadcasting itself
works — events, channels, presence, authorization, Echo — defer to Laravel's
broadcasting docs.** They are the source of truth and version-specific; this
skill does not duplicate them.

The `vask/laravel` package ships:

- `vask:install` — onboards the user and writes `.env`.
- `vask:doctor` — validates the configuration.
- `VaskWebhookController` + payload classes + a `Vask` facade for webhook
  handler registration.

## Onboarding and setup

Both setup flows are valid. Choose the least interruptive path that fits the
environment:

- **Agent signup**: use when an agent can use the user's GitHub-published
  SSH public key and can write credentials into the target project's `.env`.
- **Device flow**: use for a human-facing interactive terminal session or
  when SSH signing prerequisites are not met.

### Agent signup

Agent signup registers or recovers the user's default Vask app without a
browser or OAuth prompt. It signs a short JSON payload with the user's local SSH
private key and Vask verifies the public key against the user's GitHub user account.

Prerequisites:

- Ask for or derive the GitHub username. Use the exact GitHub username, not an
  email address.
- Prefer the SSH key used for GitHub.
- The GitHub account must be at least 14 days old.

Important rules:

- Sign the inner payload bytes exactly as sent in the outer JSON.
- Generate a fresh Unix timestamp and nonce for every request.
- Use SSHSIG namespace `vask-register`.
- Never log, print, upload, or otherwise expose the private key.
- Re-running signup is safe; it recovers the same default app credentials.
- Do not expose or invent a numeric Vask user ID. The API does not return one.

Endpoint:

```text
POST https://vask.dev/api/agent-signup
```

Request shape:

```json
{
  "payload": "{\"github_username\":\"USER\",\"timestamp\":1778580000,\"nonce\":\"UUID\",\"intent\":\"register\"}",
  "signature": "-----BEGIN SSH SIGNATURE-----\n...\n-----END SSH SIGNATURE-----",
  "claimed_pubkey": "ssh-ed25519 ..."
}
```

Shell core:

```shell
BASE_URL="${BASE_URL:-https://vask.dev}"
: "${GITHUB_USERNAME:?Set GITHUB_USERNAME to the user's GitHub username}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"

if [ ! -f "$SSH_KEY" ] && [ -f "$HOME/.ssh/id_rsa" ]; then
  SSH_KEY="$HOME/.ssh/id_rsa"
fi

[ -f "$SSH_KEY" ] || { echo "No SSH key found at $SSH_KEY or ~/.ssh/id_rsa" >&2; exit 1; }

PAYLOAD=$(jq -cn \
  --arg github_username "$GITHUB_USERNAME" \
  --arg nonce "$(uuidgen)" \
  --argjson timestamp "$(date +%s)" \
  '{github_username:$github_username,timestamp:$timestamp,nonce:$nonce,intent:"register"}')

SIGNATURE=$(printf '%s' "$PAYLOAD" | ssh-keygen -Y sign -f "$SSH_KEY" -n vask-register 2>/dev/null)
PUBKEY=$(ssh-keygen -y -f "$SSH_KEY")

curl -sS -X POST "$BASE_URL/api/agent-signup" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "$(jq -n --arg payload "$PAYLOAD" --arg signature "$SIGNATURE" --arg pubkey "$PUBKEY" \
    '{payload:$payload, signature:$signature, claimed_pubkey:$pubkey}')"
```

Successful responses include the GitHub username, whether the Vask account is
new, and a default app credential block:

```json
{
  "status": "ok",
  "user": {
    "github_username": "USER",
    "is_new_account": false
  },
  "app": {
    "id": "user-default",
    "name": "USER-default",
    "credentials": {
      "PUSHER_APP_ID": "same-as-key",
      "PUSHER_APP_KEY": "...",
      "PUSHER_APP_SECRET": "...",
      "PUSHER_HOST": "wss.vask.dev",
      "PUSHER_PORT": "443",
      "PUSHER_SCHEME": "https"
    }
  }
}
```

Write the returned `PUSHER_*` credentials into the target Laravel project's
`.env`, preserving unrelated keys. Set `PUSHER_APP_CLUSTER=mt1` if it is
missing, and keep `VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"` in sync for Vite
frontends. Then run `php artisan vask:doctor`.

Error handling:

- `invalid_payload`: fix the inner JSON or GitHub username format.
- `payload_expired`: regenerate payload, timestamp, nonce, and signature.
- `nonce_reused`: regenerate payload, nonce, timestamp, and signature, then retry.
- `pubkey_not_published`: ask the user to upload the matching public key to GitHub.
- `invalid_signature`: verify the signed bytes, SSH key, and claimed public key match.
- `abuse_filter_failed`: GitHub account is too new; use another eligible account or wait.
- `rate_limited`: back off before retrying.
- `github_api_failed`: GitHub is unavailable or rate-limited; retry later.

### Device flow

`php artisan vask:install` provisions credentials via the browser-backed device
authorization flow:

```shell
php artisan vask:install
```

It runs an OAuth 2.0 device authorization flow (RFC 8628):

1. The agent receives a verification URL and an 8-character code.
2. The user opens the URL in their browser; the URL includes the code
   pre-filled so they only need to click "Approve".
3. The user signs up or signs in on `vask.dev` — email verification, captcha,
   and abuse prevention all happen on the browser side, not in the agent.
4. The command receives an access token plus a default app's `app_key`,
   `app_secret`, and `app_id`.
5. The command writes the `PUSHER_*` block to `.env` (preserving existing
   content) and runs `vask:doctor` to confirm.

**Critical rules for the agent:**

- For device flow, do not scrape `git config user.email`, `GH_TOKEN`,
  environment variables, or local files for an identity. The browser side owns
  identity, signup, verification, captcha, and abuse prevention.
- If `vask:install` fails, use agent signup only when its prerequisites are met;
  otherwise surface the error.
- The command preserves the rest of `.env`. Do not pre-edit `.env` to "help".

When invoking non-interactively (CI, scripted runs, agent harness without TTY
forwarding), use `--force` to skip the prompt. **Do not** pass `--force` for a
human-facing interactive run — let the user see and confirm the diff.

Flags:

- `--env=path/to/.env` — write to a non-default `.env`.
- `--force` — overwrite existing `PUSHER_*` values without prompting.
- `--no-doctor` — skip the chained verification.

Override the API base for staging/local Vask backends with the `VASK_API_URL`
environment variable.

## Verify the configuration

`vask:doctor` is the canonical "is this set up?" check:

```shell
php artisan vask:doctor          # 6 static checks
php artisan vask:doctor --no-ping --no-broadcast   # skip live network checks (static checks only)
```

It verifies: `pusher/pusher-php-server` is loaded, the broadcast driver is
`pusher`, host is `wss.vask.dev`, port is `443`, scheme is `https`, and
`PUSHER_APP_KEY` / `PUSHER_APP_SECRET` / `PUSHER_APP_ID` are non-empty. Run
this first if a user reports "realtime isn't working".

## Frontend setup

`vask:install` does **not** touch the frontend. If the application has a
JS frontend that needs to subscribe to channels, this step is always required.

Follow existing customs or Laravel Broadcasting documentation to set this up.

The setup path should wire `VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"` into
`.env`, so Vite picks the key up automatically — no extra work needed.

## Usage

**This is plain Laravel broadcasting.** Anything that works against the
Pusher driver works against Vask. Use the version-appropriate Laravel
broadcasting docs (linked above) for `ShouldBroadcast` events, channel
authorization in `routes/channels.php`, presence channels, private channels,
`broadcastAs`, `broadcastWith`, `toOthers()`, queues, Echo subscriptions, etc.

Minimal reference so the shape is obvious — full API in Laravel's docs:

```php
class OrderShipped implements ShouldBroadcast
{
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.'.$this->order->id);
    }
}
```

```js
Echo.private(`orders.${orderId}`).listen('.order.shipped', e => console.log(e));
```

### Vask-specific notes when working with Laravel broadcasting

- The Echo client config has Vask-specific values (`wsHost: 'wss.vask.dev'`,
  any `cluster` string).
- Vask supports public, private, and presence channels — no behavioural
  differences from Pusher.

## Webhooks

Vask sends Pusher-compatible webhooks for server-side reactions to realtime
activity. Useful for tracking presence in the DB, audit logging, starting or
stopping jobs when channels go live, etc.

### Events

| Event              | When                                                      |
| ------------------ | --------------------------------------------------------- |
| `channel_occupied` | First subscriber on a channel.                            |
| `channel_vacated`  | Last subscriber left.                                     |
| `member_added`     | Presence channel: user joined.                            |
| `member_removed`   | Presence channel: user left.                              |
| `client_event`     | Client published on a private/presence channel (no public channels). |

### Receiving webhooks

This package ships a controller that verifies the Pusher-compatible signature
(HMAC-SHA256 of the raw body using `PUSHER_APP_SECRET`) and dispatches each
event to a registered handler. **Do not write a custom controller and do not
add a route yourself** — just register handlers on the `Vask` facade. The
package auto-registers `POST /webhooks/vask` (named `vask.webhook`) the first
time it sees a handler. No handler = no route, no overhead.

#### Register handlers

In a service provider's `boot()` method:

```php
use Vask\Laravel\Facades\Vask;
use Vask\Laravel\Webhooks\Payloads\ChannelOccupiedPayload;
use Vask\Laravel\Webhooks\Payloads\MemberAddedPayload;
use Vask\Laravel\Webhooks\Payloads\ClientEventPayload;

Vask::onChannelOccupied(fn (ChannelOccupiedPayload $event) => /* ... */);
Vask::onMemberAdded([MemberHandler::class, 'joined']);    // [class, method]
Vask::onClientEvent(LogClientEvent::class);               // invokable class
```

That's it — `php artisan route:list` will show `POST /webhooks/vask`
(`vask.webhook`) once handlers are registered. The route is registered outside
the `web` middleware group, so CSRF and sessions don't apply.

Configure the Vask dashboard to send webhooks to
`https://your-app.example.com/webhooks/vask`.

#### Custom path or middleware

```php
// In a service provider's boot()
Vask::webhookPath('/api/vask-hooks');         // change the route URI
Vask::disableAutoWebhookRoute();              // opt out entirely, register your own
```

If you opt out, register the route yourself and add whatever middleware you
need (`throttle`, `signed`, etc.):

```php
Route::post('/api/vask-hooks', VaskWebhookController::class)
    ->middleware('throttle:60,1')
    ->name(Vask::ROUTE_NAME);
```

#### Payload shapes

Each handler receives a typed, readonly payload:

| Event              | Payload class             | Properties                                                  |
| ------------------ | ------------------------- | ----------------------------------------------------------- |
| `channel_occupied` | `ChannelOccupiedPayload`  | `channel`, `timeMs`                                         |
| `channel_vacated`  | `ChannelVacatedPayload`   | `channel`, `timeMs`                                         |
| `member_added`     | `MemberAddedPayload`      | `channel`, `userId`, `timeMs`                               |
| `member_removed`   | `MemberRemovedPayload`    | `channel`, `userId`, `timeMs`                               |
| `client_event`     | `ClientEventPayload`      | `channel`, `event`, `data`, `socketId`, `userId`, `timeMs`  |

Handlers are resolved through the container, so dependencies are auto-injected.
Events with no registered handler are silently skipped.

### Async handling

Handlers run synchronously inside the webhook request. For long work, dispatch
a job from inside the handler:

```php
Vask::onClientEvent(fn (ClientEventPayload $e) => ProcessClientEvent::dispatch($e));
```

Webhook delivery is **at-least-once**, so handlers must be idempotent.

### Endpoint requirements

Public HTTPS only. No `localhost`, private IPs, or URLs containing credentials.

Full reference: <https://vask.dev/docs/webhooks.md>

## Migrating from Pusher

It's a three-line `.env` change:

```diff
- PUSHER_HOST=ws-eu.pusher.com
+ PUSHER_HOST=wss.vask.dev
- PUSHER_APP_KEY=your_pusher_key
+ PUSHER_APP_KEY=your_vask_key
- PUSHER_APP_SECRET=your_pusher_secret
+ PUSHER_APP_SECRET=your_vask_secret
```

Client code, server code, and the Pusher SDK itself stay the same. Or run
`php artisan vask:install` and let it write the new values in place.

## Migrating from Reverb

Switch `BROADCAST_CONNECTION` from `reverb` back to `pusher` and run
`vask:install` to populate the host and credentials. Event classes,
broadcasting calls, and channel auth are all unchanged.

## Gotchas

- **Cluster value is meaningless.** Don't waste tokens trying to pick the right
  one. Any non-empty string works for Pusher SDKs; Vask ignores it.
- **`pusher/pusher-php-server` is required.** This package already declares it
  in `composer.json`, so a clean `composer install` pulls it in. If a user
  has a partial install, `vask:doctor` will catch it.
- **TLS is required.** `forceTLS: true` on the client, `PUSHER_SCHEME=https`
  on the server. There is no plaintext endpoint.
- **Raw body for webhook signature.** The shipped controller handles this
  correctly. If a user writes a custom controller anyway (don't let them),
  they must read `$request->getContent()` before any JSON middleware mutates
  the body.

## Debugging

- Run `php artisan vask:doctor` first (it pings and broadcasts a test event by default).
- Test the raw socket: `wss://wss.vask.dev/app/<app_key>?protocol=7&client=js&version=8.4.0&flash=false`.
- Vask has an in-browser tester at <https://vask.dev/tools/websocket-tester>.
- The dashboard at <https://vask.dev> shows live connection counts and recent events.
