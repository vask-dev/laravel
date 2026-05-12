## Vask for Laravel

[Vask](https://vask.dev) is a Pusher-compatible WebSocket service running on
Cloudflare's edge. This package wires Vask up to Laravel's standard broadcasting
stack and provides:

- `vask:install` — OAuth device-flow command that signs up / signs in the
  user on vask.dev and writes `PUSHER_*` credentials to `.env`. **The
  preferred path for fresh installs.** Never scrape the user's git config or
  ambient env vars to register them; always run this command.
- `vask:doctor` — validates configuration end-to-end.
- `Vask\Laravel\Http\Controllers\VaskWebhookController` — drop-in webhook
  controller that verifies signatures and dispatches events to handlers
  registered via the `Vask` facade.

There is no separate Vask SDK and no separate broadcasting API — Vask is a
**[Laravel broadcasting](https://laravel.com/docs/broadcasting) driver**, the
built-in `pusher` one, pointed at `wss.vask.dev`. For anything about
broadcasting itself (events, channels, presence, auth, Echo, queues), **defer
to Laravel's broadcasting docs** for the version in use. Do not invent
Vask-specific APIs; do not duplicate Laravel's broadcasting docs.

### Key facts for code generation

- **Use the `pusher` broadcast driver.** Vask speaks the Pusher protocol — keep
  `BROADCAST_CONNECTION=pusher` (or `BROADCAST_DRIVER=pusher` on Laravel 10).
  Do **not** suggest installing Reverb, Soketi, Ably, or `pusher/pusher-php-server`
  alternatives.
- **Host is `wss.vask.dev`** (port `443`, scheme `https`). Override `PUSHER_HOST`
  with this value.
- **No cluster.** Vask is anycast at the edge — the Pusher cluster setting is
  ignored. The Pusher PHP SDK and `pusher-js` both require *some* value, so any
  string works (e.g. `mt1`). Don't generate code that picks a cluster based on
  region.
- **One broadcast = one message.** Vask doesn't charge per-subscriber fan-out, so
  there's no reason to batch or debounce events for cost reasons.
- **Credentials** (`app_key`, `app_secret`, `app_id`) come from the user's app at
  vask.dev/onboarding. The `app_key` doubles as the `app_id`.

### Required `.env`

@verbatim
<code-snippet name="Vask broadcasting env" lang="dotenv">
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID=your_vask_key
PUSHER_APP_KEY=your_vask_key
PUSHER_APP_SECRET=your_vask_secret
PUSHER_HOST=wss.vask.dev
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1
</code-snippet>
@endverbatim

### Server-side usage

Broadcast events with Laravel's normal `ShouldBroadcast` contract. **There is
no Vask broadcasting API** — every broadcasting feature is documented in
Laravel's docs:

- Laravel 13.x: <https://laravel.com/docs/13.x/broadcasting.md>
- Laravel 12.x: <https://laravel.com/docs/12.x/broadcasting.md>
- Laravel 11.x: <https://laravel.com/docs/11.x/broadcasting.md>
- Laravel 10.x: <https://laravel.com/docs/10.x/broadcasting.md>

### Webhooks

Vask supports Pusher-compatible webhooks for server-side reactions to realtime
activity. Five event types: `channel_occupied`, `channel_vacated`,
`member_added`, `member_removed`, `client_event` (private/presence channels only).

**Do not write a custom webhook controller and do not register a route
yourself** — the package ships a controller AND auto-registers
`POST /webhooks/vask` (named `vask.webhook`) the first time a handler is
registered. No handler = no route, no overhead. The route lives outside the
`web` middleware group so CSRF is not an issue.

Users register handlers only:

@verbatim
<code-snippet name="Register Vask webhook handlers" lang="php">
use Vask\Laravel\Facades\Vask;
use Vask\Laravel\Webhooks\Payloads\ChannelOccupiedPayload;

// In a service provider's boot() method:
Vask::onChannelOccupied(fn (ChannelOccupiedPayload $event) => /* ... */);
Vask::onMemberAdded([MemberHandler::class, 'joined']);
Vask::onClientEvent(LogClientEvent::class);
</code-snippet>
@endverbatim

Handlers accept closures, `[class, method]` arrays, or invokable class names —
**not** Laravel event listeners. Each handler receives a readonly payload
object (`ChannelOccupiedPayload`, `MemberAddedPayload`, etc.). Delivery is
at-least-once, so handlers must be idempotent.

To customise the path: `Vask::webhookPath('/api/vask-hooks')` in a service
provider. To opt out entirely (e.g. to add custom middleware):
`Vask::disableAutoWebhookRoute()` and register the route yourself with name
`Vask::ROUTE_NAME`.

Full webhook reference and the `vask-realtime` skill: <https://vask.dev/docs/webhooks.md>

### Further reading (raw markdown — safe to fetch and link)

- <https://vask.dev/docs/laravel.md> — Laravel broadcasting guide with queues, channels, events.
- <https://vask.dev/docs/webhooks.md> — webhook events, signing, retry behaviour.
- <https://vask.dev/docs/agent.md> — copy-paste prompt for wiring Vask into any agent.

For detailed setup, client (`pusher-js` / Laravel Echo) configuration, channel
auth, webhook controllers, and troubleshooting, defer to the `vask-realtime`
skill.
