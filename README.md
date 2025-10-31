# Laravel Lockout

<br>
<p align="center"><img src="./repo/banner.png" /></p>
<br>
    
<p align="center">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/beliven-it/laravel-lockout.svg?style=for-the-badge&labelColor=2a2c2e&color=0fbccd)](https://packagist.org/packages/beliven-it/laravel-lockout)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/beliven-it/laravel-lockout/run-tests.yml?branch=main&label=tests&style=for-the-badge&labelColor=2a2c2e&color=0fbccd)](https://github.com/beliven-it/laravel-lockout/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/beliven-it/laravel-lockout/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=for-the-badge&labelColor=2a2c2e&color=0fbccd)](https://github.com/beliven-it/laravel-lockout/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/beliven-it/laravel-lockout.svg?style=for-the-badge&labelColor=2a2c2e&color=0fbccd)](https://packagist.org/packages/beliven-it/laravel-lockout)

</p>

A small, easy-to-use package to lock accounts after repeated failed login attempts. Focused on simple integration and sensible defaults for production use.

---

## Install

Install via Composer:

```bash
composer require beliven-it/laravel-lockout
```

The package auto-registers its service provider.

---

## Publish

Publish configuration and migrations:

```bash
php artisan vendor:publish --tag="laravel-lockout-config"
php artisan vendor:publish --tag="laravel-lockout-migrations"
php artisan migrate
```

Check `config/lockout.php` after publishing to tune behavior.

---

## Quick start (3 steps)

1. Add the trait (optional convenience) to your auth model:

- Use the `HasLockout` trait on your `User` model to get `isLockedOut()`, `lock()`, `unlock()`.

Trait example:
```php
<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Beliven\Lockout\Traits\HasLockout;

class User extends Authenticatable
{
    use HasLockout;
    // ...
}
```

2. Protect the login route:

- Apply the `EnsureUserIsNotLocked` middleware to your login route to prevent requests from locked identifiers (returns 429).

Middleware example:
```php
<?php
use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;

Route::post('/login', [LoginController::class, 'login'])
    ->middleware(EnsureUserIsNotLocked::class);
```

3. Let the package record failed attempts:

- The package listens to Laravel's authentication `Failed` event and increments attempts automatically. When the configured threshold is reached the package will dispatch `EntityLocked` and listeners will handle side effects.

---

## Behavior & config (short)

Key config options (in `config/lockout.php`):

| Environment variable | Config key | Default | Description |
|---|---:|---|---|
| `LOCKOUT_LOGIN_FIELD` | `login_field` | `email` | Field used as identifier (e.g. email or username). |
| `LOCKOUT_UNLOCK_VIA_NOTIFICATION` | `unlock_via_notification` | `true` | Whether to send a signed unlock link via notification. |
| `LOCKOUT_NOTIFICATION_CLASS` | `notification_class` | `\Beliven\Lockout\Notifications\AccountLocked::class` | Notification class used to notify the user when locked. |
| `LOCKOUT_NOTIFICATION_CHANNELS` | `notification_channels` | `['mail']` | Channels used for the notification (e.g. `mail`, `database`). |
| `LOCKOUT_MAX_ATTEMPTS` | `max_attempts` | `5` | Number of failed attempts before lockout. |
| `LOCKOUT_DECAY_MINUTES` | `decay_minutes` | `30` | Time window (minutes) to count failed attempts. |
| `LOCKOUT_CACHE_STORE` | `cache_store` | `database` | Cache store used for counters (e.g. `redis`, `database`, `array`). |

Defaults are safe for most apps; override env values to customize.

---

## Unlocking

- When enabled, the package can send a signed unlock link to the user.
- The link routes to an unlock controller that clears the model's `blocked_at`.
- The unlock flow uses a temporary signed route and validates the signature.

---

## Events & extension points

- `EntityLocked` — dispatched when an identifier reaches the threshold.
- Default listeners:
  - record failed attempts (bound to auth `Failed` event),
  - mark the model as locked (`blocked_at`) when `EntityLocked` fires,
  - send unlock notification if enabled.

You can replace listeners or the notification class via your app's event provider or config to customize behavior.

---

## Logs / Audit

Every failed attempt is stored in `lockout_logs` with identifier, IP, user agent and timestamp — useful for auditing and investigations.

---

## Testing

The package ships a full test suite using Pest.

Run tests:

```bash
composer install --dev
./vendor/bin/pest
```

---

## Notes & best practices

- Middleware only checks lock state (read-only). Counting happens on authentication failure to avoid false positives on successful logins.
- Use a durable cache store (e.g. Redis or DB) for counters in production.
- If you prefer a fully event-driven customization, listen to `EntityLocked` and implement your own listeners.

---

## Contributing & support

Contributions welcome. Please open issues or PRs and include tests for changes. See repository CONTRIBUTING guidelines.

---

## License

MIT — see `LICENSE.md` for details.
