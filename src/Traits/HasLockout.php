<?php

namespace Beliven\Lockout\Traits;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasLockout
{
    /**
     * Determine if the model is considered locked.
     *
     * This checks both the persistent `blocked_at` flag on the model and the
     * in-memory/cache attempt counter maintained by the Lockout service.
     */
    public function isLockedOut(): bool
    {
        // Check persistent block first
        if (isset($this->blocked_at) && $this->blocked_at) {
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
     * Morph relation to the lockout logs associated with this model.
     *
     * Provides easy access to the LockoutLog entries for this entity.
     */
    public function lockoutLogs(): MorphMany
    {
        return $this->morphMany(\Beliven\Lockout\Models\LockoutLog::class, 'model');
    }

    /**
     * Unlock the model by clearing the persistent blocked flag.
     */
    public function unlock(): void
    {
        $this->blocked_at = null;
        $this->save();
    }

    /**
     * Mark the model as locked (set the blocked timestamp).
     */
    public function lock(): void
    {
        $this->blocked_at = now();
        $this->save();
    }
}
