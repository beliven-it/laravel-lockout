<?php

namespace Beliven\Lockout\Traits;

use Beliven\Lockout\Facades\Lockout;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
     * Determine if the model is considered locked.
     *
     * This checks both the persistent lock records (ModelLockout) and the
     * in-memory/cache attempt counter maintained by the Lockout service.
     */
    public function isLockedOut(): bool
    {
        // Check persistent lock records first
        if ($this->activeLock() !== null) {
            return true;
        }

        // Fallback to the lockout service's attempt counter.
        $identifier = Lockout::getLoginField();

        // Guard in case the model does not expose the configured login field.
        if (!isset($this->{$identifier})) {
            return false;
        }

        return Lockout::hasTooManyAttempts((string) $this->{$identifier});
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
        $attributes = [
            'locked_at'  => $options['locked_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'reason'     => $options['reason'] ?? null,
            'meta'       => $options['meta'] ?? null,
        ];

        return $this->lockouts()->create($attributes);
    }

    /**
     * Unlock the model by marking the active lock as unlocked.
     *
     * If no active lock exists this is a no-op.
     */
    public function unlock(): void
    {
        $lock = $this->activeLock();
        if ($lock) {
            $lock->markUnlocked();
        }
    }
}
