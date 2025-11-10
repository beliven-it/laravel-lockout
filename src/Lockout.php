<?php

namespace Beliven\Lockout;

use Beliven\Lockout\Contracts\LockableModel;
use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Models\LockoutLog;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Lockout
{
    protected int $maxAttempts;

    protected int $decayMinutes;

    protected string $cacheStore;

    protected bool $unlockViaNotification;

    protected bool $lockOnLogin;

    public function __construct()
    {
        $this->maxAttempts = config('lockout.max_attempts', 5);
        $this->decayMinutes = config('lockout.decay_minutes', 30);
        $this->cacheStore = config('lockout.cache_store', 'database');
        $this->unlockViaNotification = config('lockout.unlock_via_notification', true);
        $this->lockOnLogin = config('lockout.lock_on_login', false);
    }

    public function getAttempts(string $id): int
    {
        return Cache::store($this->cacheStore)->get(
            $this->throttleKey($id),
            0,
        );
    }

    public function incrementAttempts(string $id): void
    {
        $key = $this->throttleKey($id);
        $attempts = Cache::store($this->cacheStore)->increment($key, 1);

        if (!$attempts) {
            Cache::store($this->cacheStore)->put($key, 1, now()->addMinutes($this->decayMinutes));
        }
    }

    public function hasTooManyAttempts(string $id): bool
    {
        return Cache::store($this->cacheStore)->get(
            $this->throttleKey($id),
            0,
        ) >= $this->maxAttempts;
    }

    public function attemptLockout(string $id, object $data): bool
    {
        $wasBlocked = $this->hasTooManyAttempts($id);
        if ($wasBlocked) {
            return true;
        }

        $this->incrementAttempts($id);

        $this->createLog($id, $data);

        $isBlockedNow = $this->hasTooManyAttempts($id);

        if ($isBlockedNow && !$wasBlocked) {
            $model = $this->getLoginModel($id);
            Event::dispatch(new EntityLocked($id, $data));
        }

        return $isBlockedNow;
    }

    public function attemptSendLoginNotification(Model $model, object $data): void
    {
        if (!$this->lockOnLogin) {
            return;
        }

        $id = $model->{$this->getLoginField()};
        if (is_null($id)) {
            return;
        }

        $notificationClass = config('lockout.login_notification_class', \Beliven\Lockout\Notifications\AccountLogged::class);
        $lockoutDuration = $this->decayMinutes;

        // Use configurable lifetime for the temporary signed unlock URL (minutes)
        $minutes = (int) config('lockout.lock_link_minutes', 1440);
        $signedUnlockUrl = URL::temporarySignedRoute('lockout.lock', now()->addMinutes($minutes), [
            'identifier' => $id,
            'entropy'    => Str::random(32),
        ]);

        $notification = new $notificationClass($id, $this->decayMinutes, $signedUnlockUrl);

        if (!method_exists($model, 'notify')) {
            return;
        }

        $model->notify($notification);
    }

    public function attemptSendLockoutNotification(string $id, object $data): void
    {
        // When the user disables the unlock via notification feature
        // we can skip sending the notification
        if (!$this->unlockViaNotification) {
            return;
        }

        // Check also if the identifier is a valid email format
        if (!filter_var($id, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $notificationClass = config('lockout.notification_class', \Beliven\Lockout\Notifications\AccountLocked::class);
        $lockoutDuration = $this->decayMinutes;

        // Use configurable lifetime for the temporary signed unlock URL (minutes)
        $minutes = (int) config('lockout.unlock_link_minutes', 1440);
        $signedUnlockUrl = URL::temporarySignedRoute('lockout.unlock', now()->addMinutes($minutes), [
            'identifier' => $id,
            'entropy'    => Str::random(32),
        ]);

        $notification = new $notificationClass($id, $this->decayMinutes, $signedUnlockUrl);

        // Resolve the login model using the single-model strategy configured in auth.providers.users.model
        $model = $this->getLoginModel($id);
        if (!$model) {
            return;
        }

        if (!method_exists($model, 'notify')) {
            return;
        }

        $model->notify($notification);
    }

    public function getLoginField(): string
    {
        return config('lockout.login_field', 'email');
    }

    public function getLoginModelClass(): string
    {
        $loginField = $this->getLoginField();

        return config('auth.providers.users.model');
    }

    public function getLoginModel(string $identifier): null|LockableModel|Model
    {
        $modelClass = $this->getLoginModelClass();

        return $modelClass::where($this->getLoginField(), $identifier)->first();
    }

    public function lockModel(LockableModel $model, ?array $options = []): ?ModelLockout
    {
        $attributes = [
            'locked_at'  => $options['locked_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'reason'     => $options['reason'] ?? null,
            'meta'       => $options['meta'] ?? null,
        ];

        try {
            return $model->lockouts()->create($attributes);
        } catch (\Throwable $_) {
            // Keep the service resilient in environments where relation creation
            // could fail (tests or constrained DB). Return null to indicate failure.
            return null;
        }
    }

    public function unlockModel(LockableModel $model, ?array $options = []): ?ModelLockout
    {
        $lock = $model->activeLock();
        if (!$lock) {
            return null;
        }

        // Apply optional reason/meta/actor before unlocking
        if (array_key_exists('reason', $options) && $options['reason'] !== null) {
            $lock->reason = $options['reason'];
        }

        if (array_key_exists('meta', $options) && $options['meta'] !== null) {
            $existing = (array) ($lock->meta ?? []);
            $lock->meta = array_merge($existing, (array) $options['meta']);
        }

        if (array_key_exists('actor', $options) && $options['actor'] !== null) {
            $existing = (array) ($lock->meta ?? []);
            $existing['actor'] = $options['actor'];
            $lock->meta = $existing;
        }

        // Mark unlocked and persist
        $lock->unlocked_at = now();

        try {
            $saved = $lock->save();
        } catch (\Throwable $e) {
            // Failed to save the lock record; return null to indicate failure.
            return null;
        }

        // Clear attempt counters for the concrete identifier value (e.g. user's email)
        $loginField = $this->getLoginField();
        $identifierValue = $model->$loginField ?? null;
        if ($identifierValue) {
            try {
                $this->clearAttempts((string) $identifierValue);
            } catch (\Throwable $_) {
                // ignore clearing attempts failures
            }
        }

        // Dispatch EntityUnlocked event so listeners can handle notifications/audit
        try {
            $requestData = isset($options['requestData']) && is_object($options['requestData'])
                ? $options['requestData']
                : (object) $options;

            event(new \Beliven\Lockout\Events\EntityUnlocked($model, $lock, (string) ($identifierValue ?? ''), $requestData));
        } catch (\Throwable $_) {
            // ignore event dispatch failures
        }

        return $saved ? $lock : null;
    }

    /**
     * Clear stored attempt counter for an identifier.
     *
     * This is used when a model is unlocked so that the in-memory / cache
     * counter is cleared and the normal flow (allowing login attempts again)
     * works as expected.
     *
     * Example usage from ModelLockout::markUnlocked():
     *   \Beliven\Lockout\Facades\Lockout::clearAttempts($identifier);
     */
    public function clearAttempts(string $id): void
    {
        Cache::store($this->cacheStore)->forget($this->throttleKey($id));
    }

    protected function createLog(string $id, object $data): void
    {
        $logModel = new LockoutLog;

        $logModel->identifier = $id;
        $logModel->ip_address = $data->ip ?? null;
        $logModel->user_agent = $data->user_agent ?? null;
        $logModel->attempted_at = now();

        // Attempt to associate the created log with the configured login model
        // (single-model strategy). Be defensive: swallow any errors so tests and
        // constrained environments without the users table do not fail.
        /*
        try {
            $model = $this->getLoginModel($id);
            if ($model instanceof Model) {
                $logModel->model()->associate($model);
            }
        } catch (\Throwable $_) {
            // ignore association failures
        }
        */

        $logModel->save();
    }

    protected function throttleKey(string $id): string
    {
        return 'login-attempts:' . $id;
    }
}
