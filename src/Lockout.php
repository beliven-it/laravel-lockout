<?php

namespace Beliven\Lockout;

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Models\LockoutLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Lockout
{
    protected int $maxAttempts;

    protected int $decayMinutes;

    protected string $cacheStore;

    protected bool $unlockViaNotification;

    public function __construct()
    {
        $this->maxAttempts = config('lockout.max_attempts', 5);
        $this->decayMinutes = config('lockout.decay_minutes', 30);
        $this->cacheStore = config('lockout.cache_store', 'database');
        $this->unlockViaNotification = config('lockout.unlock_via_notification', true);
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
            Event::dispatch(new EntityLocked($id, $data));
        }

        return $isBlockedNow;
    }

    public function attemptSendLockoutNotification(string $id, object $data): void
    {
        // When the use disable the unlock via notification feature
        // we can skip sending the notification
        if (!$this->unlockViaNotification) {
            return;
        }

        // Check also if the column is a valid email format
        if (!filter_var($id, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $notificationClass = config('lockout.notification_class', \Beliven\Lockout\Notifications\AccountLocked::class);
        $lockoutDuration = $this->decayMinutes;

        $signedUnlockUrl = URL::temporarySignedRoute('lockout.unlock', now()->addDay(), [
            'identifier' => $id,
            'entropy'    => Str::random(32),
        ]);

        $notification = new $notificationClass($id, $this->decayMinutes, $signedUnlockUrl);

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

    public function getLoginModel(string $identifier): ?Model
    {
        $modelClass = $this->getLoginModelClass();

        return $modelClass::where($this->getLoginField(), $identifier)->first();
    }

    protected function throttleKey(string $id): string
    {
        return 'login-attempts:' . $id;
    }

    protected function createLog(string $id, object $data): void
    {
        $logModel = new LockoutLog;

        $logModel->identifier = $id;
        $logModel->ip_address = $data->ip ?? null;
        $logModel->user_agent = $data->user_agent ?? null;
        $logModel->attempted_at = now();

        // Attempt to associate the log entry with the concrete Eloquent model
        // (if a model exists for the given identifier). The migration provides a
        // nullable morph column named `model` so we associate via that morph.
        try {
            $relatedModel = $this->getLoginModel($id);
            if ($relatedModel) {
                // Use morph association if available on the log model.
                // The LockoutLog model exposes a morph relation `model()`.
                if (method_exists($logModel, 'model')) {
                    $logModel->model()->associate($relatedModel);
                } else {
                    // Fallback: set morph type/id directly if relation method is absent.
                    $logModel->setAttribute('model_type', get_class($relatedModel));
                    $logModel->setAttribute('model_id', $relatedModel->getKey());
                }

                // If the attempt caused the identifier to be considered blocked (i.e. threshold reached)
                // and there is no active lock recorded for the model yet, create a model lock record.
                // This ensures the package records a persistent lock in the dedicated `model_lockouts`
                // table when appropriate.
                try {
                    if ($this->hasTooManyAttempts($id)) {
                        $hasActive = false;

                        // Prefer model-provided activeLock() helper if available.
                        if (method_exists($relatedModel, 'activeLock')) {
                            try {
                                $hasActive = (bool) $relatedModel->activeLock();
                            } catch (\Throwable $_) {
                                $hasActive = false;
                            }
                        } elseif (method_exists($relatedModel, 'lockouts')) {
                            // Fallback: query the relation for an active lock.
                            try {
                                $hasActive = (bool) $relatedModel->lockouts()
                                    ->whereNull('unlocked_at')
                                    ->where(function ($q) {
                                        $q->whereNull('expires_at')
                                            ->orWhere('expires_at', '>', now());
                                    })
                                    ->exists();
                            } catch (\Throwable $_) {
                                $hasActive = false;
                            }
                        }

                        // If no active lock exists, create one. Prefer calling the model's
                        // `lock()` method when available so model-specific logic executes.
                        if (!$hasActive) {
                            if (method_exists($relatedModel, 'lock')) {
                                try {
                                    $relatedModel->lock();
                                } catch (\Throwable $_) {
                                    // swallow and continue; lock creation is best-effort here
                                }
                            } elseif (method_exists($relatedModel, 'lockouts')) {
                                try {
                                    $relatedModel->lockouts()->create([
                                        'locked_at' => now(),
                                    ]);
                                } catch (\Throwable $_) {
                                    // swallow and continue
                                }
                            }
                        }
                    }
                } catch (\Throwable $_) {
                    // Ignore any errors when attempting to inspect/create lock records.
                }
            }
        } catch (\Throwable $e) {
            // If association fails for any reason, ignore and still persist the log.
        }

        $logModel->save();
    }
}
