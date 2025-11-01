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

## Publishable contents

1) Publish the config
```bash
php artisan vendor:publish --tag="lockout-config"
```

2) Publish and review the migration stubs
```bash
php artisan vendor:publish --tag="lockout-migrations"
php artisan migrate
```

---

## Setup

1. Add the trait to your auth model:

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

2. Apply the middleware to your login route:

```php
<?php
use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;

Route::post('/login', [LoginController::class, 'login'])
    ->middleware(EnsureUserIsNotLocked::class);
```

---

## Configuration

Configuration options are well described in `config/lockout.php`. Here is a brief overview with defaults:

```php
<?php

return [
    'login_field' => 'email',
    'unlock_via_notification' => true,
    'notification_class' => \Beliven\Lockout\Notifications\AccountLocked::class,
    'notification_channels' => ['mail'],
    'max_attempts' => 5,
    'decay_minutes' => 30,
    'cache_store' => 'database',
    'auto_unlock_hours' => 0,
    'prune' => [
        'enabled' => true,
        'lockout_logs_days' => 90,
        'model_lockouts_days' => 365,
    ],
];

```

Defaults are safe for most apps; override env values to customize.

---

## Behaviors

### Unlocking

- When enabled, the package can send a signed unlock link to the user.
- The link routes to an unlock controller that clears the model's active lock record in the `model_lockouts` table.
- The unlock flow uses a temporary signed route and validates the signature.

### Pruning & retention

The package records both short-term throttling state (cache-based counters) and persistent records (`model_lockouts` and `lockout_logs`). Keeping a history of lock events is useful for auditing and security analysis, but historic data can accumulate over time. The package provides a configurable pruning facility to remove old records.

#### Configuration
- `config/lockout.php` exposes a `prune` section (enabled by default) with:
  - `prune.enabled` (bool) — enable/disable pruning
  - `prune.lockout_logs_days` (int) — days to retain `lockout_logs` (default: 90)
  - `prune.model_lockouts_days` (int) — days to retain `model_lockouts` history (default: 365)

#### Artisan command
- Use the included command to prune old records:

  - `php artisan lockout:prune`  
    Runs pruning for both `lockout_logs` and `model_lockouts` using configured retention days. By default the command asks for confirmation.

  - Useful options:
    - `--days-logs=NN` — override days for `lockout_logs`
    - `--days-models=NN` — override days for `model_lockouts`
    - `--only-logs` / `--only-model` — prune only one table
    - `--force` — run without confirmation (suitable for scheduler)

#### Behavior
- `lockout_logs` pruning removes log entries older than the configured cutoff (based on the `attempted_at` timestamp).
- `model_lockouts` pruning removes unlocked historical lock records only (records where `unlocked_at` is not null and older than the configured cutoff). Active locks are never automatically pruned by this command.

#### Scheduling
- It's recommended to run the prune command regularly (e.g. nightly) via Laravel's scheduler:
  ```php
  $schedule->command('lockout:prune --force')->daily();
  ```

#### Notes
- Pruning is best-effort and configurable; keep audit requirements in mind when choosing retention durations.
- If you prefer a lighter approach (no history), you can configure the application to delete lock records on unlock — but consider keeping `lockout_logs` for auditing.

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
<?php
//....
$table->nullableMorphs('model');
$table->string('identifier')->nullable();
$table->string('ip_address')->nullable();
$table->text('user_agent')->nullable();
$table->timestamp('attempted_at');
```

Usage example
```php
<?php
// ...
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

### Example: Laravel Nova Action

If you use Laravel Nova and want an admin Action to unlock selected users, you can call the model helper `unlock()` (which delegates to the Lockout service). The example below shows a simple Nova Action that unlocks each selected resource and records an optional reason/meta.

```php
<?php

namespace App\Nova\Actions;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Illuminate\Support\Collection;

class UnlockUsers extends Action
{
    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Unlock Users';

    /**
     * Perform the action on the given models.
     *
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection  $models
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $model) {
            // Call the trait-provided helper. You can pass optional metadata or a reason.
            // The trait delegates to the Lockout service so behavior is consistent with
            // other codepaths (listeners/commands/controllers).
            $model->unlock([
                'reason' => 'Manual admin unlock',
                'meta' => ['via' => 'nova'],
            ]);
        }

        return Action::message('Selected users have been unlocked.');
    }

    /**
     * Fields shown on the action UI (none in this simple example).
     *
     * @return array
     */
    public function fields()
    {
        return [];
    }
}
```

Register the action on your Nova resource (for example `App\Nova\User`):

```php
<?php
// in App\Nova\User.php

use App\Nova\Actions\UnlockUsers;

public function actions(Request $request)
{
    return [
        new UnlockUsers,
    ];
}
```

Notes
- Ensure your Eloquent auth model uses `Beliven\Lockout\Traits\HasLockout`.
- `unlock()` returns the updated `ModelLockout` instance (or `null` if no active lock existed) — adapt your Action behavior if you need to inspect the result.
- For auditing, you can pass a `reason` and `meta` array; listeners for the `EntityUnlocked` event can react to the unlock and perform notifications or extra logging.

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
