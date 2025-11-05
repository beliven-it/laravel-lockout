<?php

namespace Beliven\Lockout\Traits;

use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Trait HasLockout
 *
 * Provides a polymorphic lock mechanism backed by the `model_lockouts` table.
 * This trait keeps a history of lock records and exposes helpers to check and
 * mutate the lock state in a model-agnostic fashion.
 */
trait HasLockout
{
    /**
     * Morph relation to the lockout logs associated with this model.
     *
     * Provides easy access to the LockoutLog entries for this entity.
     */
    public function lockoutLogs(): MorphMany
    {
        return $this->morphMany(\Beliven\Lockout\Models\LockoutLog::class, 'model');
    }

    /**
     * Morph relation to the lock records (ModelLockout) associated with this model.
     *
     * These records represent persistent locks (and their history) applied to the model.
     */
    public function lockouts(): MorphMany
    {
        return $this->morphMany(ModelLockout::class, 'model');
    }

    /**
     * Return the currently active lock record for this model, or null when none exists.
     *
     * An active lock is one where `unlocked_at` is null and `expires_at` is null or in the future.
     */
    public function activeLock(): ?ModelLockout
    {
        return $this->lockouts()
            ->whereNull('unlocked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('locked_at')
            ->first();
    }

    /**
     * Determine whether the model currently has an active lock.
     *
     * This is a boolean helper that mirrors the logic in `activeLock()` but uses
     * an `exists()` query for efficiency when only presence is required.
     */
    public function hasActiveLock(): bool
    {
        return (bool) $this->lockouts()
            ->whereNull('unlocked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Resolve the Lockout service instance.
     *
     * This method exists so consumers and tests can override the returned
     * implementation (for example by extending the model and overriding this
     * method). By default it resolves the package service from the container.
     */
    protected function resolveLockoutService(): \Beliven\Lockout\Lockout
    {
        return app(\Beliven\Lockout\Lockout::class);
    }

    /**
     * Determine if the model is considered locked.
     *
     * This checks both the persistent lock records (ModelLockout) and the
     * in-memory/cache attempt counter maintained by the Lockout service.
     */
    public function isLockedOut(): bool
    {
        // Check persistent lock records first (use efficient boolean helper).
        if ($this->hasActiveLock()) {
            return true;
        }

        // Fallback to the lockout service's attempt counter.
        $service = $this->resolveLockoutService();
        $identifier = $service->getLoginField();

        // Guard in case the model does not expose the configured login field.
        if (!isset($this->{$identifier})) {
            return false;
        }

        return $service->hasTooManyAttempts((string) $this->{$identifier});
    }

    /**
     * Create a new lock record for this model.
     *
     * Accepts optional parameters:
     *  - 'expires_at' => DateTime | null
     *  - 'reason' => string | null
     *  - 'meta' => array | null
     *
     * Returns the created ModelLockout instance.
     *
     * Example:
     *   $this->lock(['expires_at' => now()->addMinutes(30), 'reason' => 'too_many_attempts']);
     */
    public function lock(array $options = []): ModelLockout
    {
        return $this->resolveLockoutService()->lockModel($this, $options);
    }

    /**
     * Unlock the model by marking the active lock as unlocked.
     *
     * If no active lock exists this is a no-op.
     *
     * Accepts optional parameters and forwards them to the Lockout service:
     *  - 'reason' => string|null
     *  - 'meta'   => array|null
     *  - 'actor'  => mixed|null
     *  - 'requestData' => object|null
     */
    public function unlock(array $options = []): ?ModelLockout
    {
        return $this->resolveLockoutService()->unlockModel($this, $options);
    }

    public function logoutOnLockout(?string $guard = null): bool
    {
        // Default common case behavior
        // (to be overridden in concrete models if needed)
        Auth::guard($guard)->logout();
        session()->invalidate();
        session()->regenerateToken();

        return true;
    }
}
