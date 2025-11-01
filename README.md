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

Publish configuration and migrations (local development)

Note: this package is not yet published as a tagged release. To try it locally or during development, clone the repository and install it into your application (for example using a Composer path repository) or include it in your app once it is released.

Recommended local workflow:
1) Publish the config
```bash
php artisan vendor:publish --tag="laravel-lockout-config"
# then edit config/lockout.php as needed for your app
```

2) Publish and review the migration stubs
```bash
php artisan vendor:publish --tag="laravel-lockout-migrations"
# review the published migration stubs in your application and adjust if necessary
php artisan migrate
```

Notes:
- The package ships migration stubs for the audit logs and for the polymorphic `model_lockouts` table (the preferred place to store persistent locks and history).
- Always review and, if needed, edit the published migration stubs so they match your application's schema and conventions before running `php artisan migrate`.
- The package provides migration stubs:
  - `create_lockout_logs_table` — creates the `lockout_logs` table used for audit entries.
  - `create_model_lockouts_table` — creates the polymorphic `model_lockouts` table used for persistent locks and history.
- Recommended flow: publish the config and review the migration stubs before publishing/running them so they align with your application's schema and conventions.
- After publishing the migration stubs you may edit them (for example to change column placement or naming) before running `php artisan migrate`.

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
- The link routes to an unlock controller that clears the model's active lock record in the `model_lockouts` table.
- The unlock flow uses a temporary signed route and validates the signature.

---

## Events & extension points

- `EntityLocked` — dispatched when an identifier reaches the threshold.
- Default listeners:
  - record failed attempts (bound to auth `Failed` event),
  - record a model lock in the `model_lockouts` table when `EntityLocked` fires,
  - send unlock notification if enabled.

You can replace listeners or the notification class via your app's event provider or config to customize behavior.

---

## Logs / Audit

Every failed attempt is stored in `lockout_logs` with identifier, IP, user agent and timestamp — useful for auditing and investigations.

Association to a model
- Log entries can now be associated with the persistent model (for example your `User` model) when a model for the identifier exists. The `LockoutLog` model exposes a polymorphic `model()` relation so you can access the related model via `$log->model` (it will be `null` if no model is associated).

Migration note
- The package migration uses a nullable polymorphic relation for this association. The migration stub creates nullable morph columns (for example `nullableMorphs('model')`), so the table contains `model_type` and `model_id` as nullable columns.
- If you have already published the migrations previously, update your `lockout_logs` table to include the nullable morph columns or re-publish the package migration. Example schema snippet used by the package:
```php
$table->nullableMorphs('model');
$table->string('identifier')->nullable();
$table->string('ip_address')->nullable();
$table->text('user_agent')->nullable();
$table->timestamp('attempted_at');
```

Usage example
```php
use Beliven\Lockout\Models\LockoutLog;
use App\Models\User; // Replace with your actual auth model namespace

// Accessing via the LockoutLog model
$log = LockoutLog::first();
$relatedModel = $log->model; // Returns the associated model (e.g. User) or null

// Or via the model's convenience relation provided by the HasLockout trait
// (any model using the trait exposes the `lockoutLogs()` morphMany relation)
$user = User::where('email', 'test@example.com')->first();
$recentAttempts = $user->lockoutLogs()->latest('attempted_at')->take(10)->get();
$attemptsCount = $user->lockoutLogs()->count();
```

Compatibility
- This change is backward compatible: logs are still recorded with identifier and metadata even when no model exists or when association fails. The association step is attempted but non-fatal so logging will never prevent the lockout flow.

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
