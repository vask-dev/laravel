# Vask for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vask/laravel.svg?style=flat-square)](https://packagist.org/packages/vask/laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/vask-dev/laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/vask-dev/laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/vask-dev/laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/vask-dev/laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/vask/laravel.svg?style=flat-square)](https://packagist.org/packages/vask/laravel)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

```bash
composer require vask/laravel
```

Sign up (or sign in) and wire credentials into `.env` in one command:

```bash
php artisan vask:install
```

This kicks off an OAuth device flow — you'll be shown a URL and a short code
to approve in your browser. Once approved, the command writes
`PUSHER_*` credentials to `.env` and runs `vask:doctor` to confirm the
configuration. No git config or local tokens are used to authenticate.

If you need to verify an existing setup at any time:

```bash
php artisan vask:doctor
php artisan vask:doctor --no-ping --no-broadcast   # skip the live network checks
```

## Usage

Vask is a drop-in Pusher replacement, so use Laravel's standard broadcasting:

```php
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class OrderShipped implements ShouldBroadcast
{
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.'.$this->order->id);
    }
}
```

### Webhooks

Register handlers in a service provider — the package auto-registers
`POST /webhooks/vask` the first time it sees a handler. No handler = no route:

```php
use Vask\Laravel\Facades\Vask;
use Vask\Laravel\Webhooks\Payloads\ChannelOccupiedPayload;

public function boot(): void
{
    Vask::onChannelOccupied(fn (ChannelOccupiedPayload $event) => /* ... */);
    Vask::onMemberAdded([MemberHandler::class, 'joined']);
    Vask::onClientEvent(LogClientEvent::class); // invokable class
}
```

The route is registered outside the `web` middleware group, so CSRF doesn't
apply. To customise: `Vask::webhookPath('/api/vask-hooks')` or
`Vask::disableAutoWebhookRoute()` to register your own.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ashley Hindle](https://github.com/ashleyhindle)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
