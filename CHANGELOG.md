# Changelog

All notable changes to `vask/laravel` will be documented in this file.

## 0.0.14 - 2026-05-12

### What's Changed

**Fix CI.** Two CI failures had been red on the README badge:

* The `run-tests` matrix entry for PHP 8.1 + Laravel 10 + prefer-stable could no longer resolve composer dependencies (phpunit 10.5.63 conflicts with pest 2.x; pest 3+ needs PHP 8.2+). Since PHP 8.1 security support ended Dec 2024, dropping it from `composer.json` and the matrix is the cleanest fix.
* PHPStan was failing because pint's `mb_str_functions` rule had converted `rtrim`/`trim`/`ltrim` to their `mb_*` PHP 8.4 counterparts. The runtime polyfill covers them, but its untyped bootstrap makes phpstan-on-PHP-8.3 see them as returning `mixed`, cascading into return-type errors. Reverted to plain `trim` and removed the pint rule.

**Trim the outbound User-Agent.** Dropped the trailing `vask-laravel/{version}` tail. The UA is now just `{app-slug}/{env}`, e.g. `vask-web/local`.

#### Breaking

* Minimum PHP version is now **8.2** (was 8.1).

**Full Changelog**: https://github.com/vask-dev/laravel/compare/0.0.13...0.0.14

## 0.0.13 - 2026-05-12

### What's Changed

Documentation refresh. Replaces the leftover spatie/skeleton placeholder description with a real one, mentions the server/client split in the `/_vask/demo` page, and tidies the prose.

No functional or API changes.

**Full Changelog**: https://github.com/vask-dev/laravel/compare/0.0.12...0.0.13

## 0.0.12 - 2026-05-12

### What's Changed

Outbound HTTP calls now identify the calling Laravel app in their `User-Agent`.

Every request this package makes — the `vask:install` device-flow exchange and every `broadcast()` at runtime — sends:

    {app-slug}/{env} vask-laravel/{version}
    
For example: `vask-web/local vask-laravel/0.0.12`.

The app slug comes from `config('app.name')`, falling back to `basename(base_path())` when `APP_NAME` is still the Laravel default, and finally to the literal `"laravel"`. The runtime side is wired through `broadcasting.connections.pusher.client_options.headers.User-Agent` (Laravel's `BroadcastManager` forwards it into the Pusher SDK's Guzzle client). If the host app already set its own `User-Agent` there, this package leaves it alone.

**Full Changelog**: https://github.com/vask-dev/laravel/compare/0.0.11...0.0.12

## 0.0.7 - Dev, Vask Dev - 2026-05-12

### What's Changed

* Bump aglipanci/laravel-pint-action from 2.4 to 2.6 by @dependabot[bot] in https://github.com/vask-dev/laravel/pull/3

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/vask-dev/laravel/pull/3

**Full Changelog**: https://github.com/vask-dev/laravel/commits/0.0.7
