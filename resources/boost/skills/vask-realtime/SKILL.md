---
name: vask-realtime
description: Set up and use Vask (Pusher-compatible WebSockets) for realtime features in a Laravel application — onboarding, broadcasting events, Echo/pusher-js client setup, channel authentication, and webhooks.
---

# Vask Realtime

## When to use this skill

- Initial setup of Vask in a Laravel app.
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

Version-specific Laravel broadcasting docs (raw markdown, safe to fetch):

- Laravel 13.x: <https://laravel.com/docs/13.x/broadcasting.md>
- Laravel 12.x: <https://laravel.com/docs/12.x/broadcasting.md>
- Laravel 11.x: <https://laravel.com/docs/11.x/broadcasting.md>
- Laravel 10.x: <https://laravel.com/docs/10.x/broadcasting.md>

The `vask/laravel` package ships:

- `vask:install` — onboards the user and writes `.env`.
- `vask:doctor` — validates the configuration.
- `VaskWebhookController` + payload classes + a `Vask` facade for webhook
  handler registration.

## Onboarding (primary path)

**Always start here.** `php artisan vask:install` is the only sanctioned way to
provision credentials for a fresh user:

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

- **Never** scrape `git config user.email`, `GH_TOKEN`, environment variables,
  or any local file for an identity to use during signup. The device flow is
  the only path. If `vask:install` fails, surface the error — do not work
  around it by trying to register the user yourself.
- Read the URL and code aloud to the user verbatim. Do not paraphrase.
- The command preserves the rest of `.env`. Do not pre-edit `.env` to "help".

**Overwriting existing `PUSHER_*` keys.** If `.env` already has `PUSHER_*`
values that differ from what the command would write (e.g. the user was
previously on Pusher proper), `vask:install` shows a per-key diff and prompts
`Overwrite these values? [y/N]`. The secret is masked. The default is **No**,
so a blind enter aborts safely.

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

Install the client libraries:

```shell
npm install --save-dev laravel-echo pusher-js
```

Configure Echo (typically in `resources/js/bootstrap.js` or `app.js`):

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: 'wss.vask.dev',
    wsPort: 443,
    wssPort: 443,
    forceTLS: true,
    encrypted: true,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: 'mt1', // required by pusher-js, value ignored by Vask
});
```

`vask:install` already wires `VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"` into
`.env`, so Vite picks the key up automatically — no extra work needed.

## Manual backend setup (fallback)

Use this path **only** when `vask:install` cannot run — e.g. provisioning in
a CI pipeline against pre-existing credentials, air-gapped environments, or
when migrating from another provider and the user already has Vask
credentials.

Add to `.env`:

```dotenv
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID=your_vask_key
PUSHER_APP_KEY=your_vask_key
PUSHER_APP_SECRET=your_vask_secret
PUSHER_HOST=wss.vask.dev
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1
```

> On **Laravel 10**, the variable is `BROADCAST_DRIVER` (singular), not
> `BROADCAST_CONNECTION`. This package supports Laravel 10 onwards.

The `PUSHER_APP_CLUSTER` value is meaningless on Vask (anycast Cloudflare
edge — no regional cluster) but the Pusher SDKs require *a* value. Any
non-empty string works.

Run `php artisan vask:doctor` after to verify.

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
  any `cluster` string). See the [Frontend setup](#frontend-setup) section.
- Broadcasting routes (`routes/channels.php`) registration changed between
  Laravel 10 and 11 — Laravel 10 uses `BroadcastServiceProvider`, Laravel 11+
  uses `->withBroadcasting(...)` in `bootstrap/app.php`. This is a Laravel
  concern, not Vask's; the Laravel broadcasting docs for the right version
  cover it.
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
- **No fan-out fees.** If existing code batches broadcasts to reduce Pusher
  costs, that workaround is no longer needed.
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
- The dashboard at <https://vask.dev> shows live connection counts and recent
  events.
