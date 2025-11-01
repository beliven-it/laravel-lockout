<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Facades\Lockout;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class MarkModelAsLocked
{
    /**
     * Handle the event.
     */
    public function handle(EntityLocked $event): void
    {
        // Single outer-level safeguard so we never bubble exceptions
        // from lockout handling into the host application.
        try {
            $identifier = $event->identifier;
            $model = $this->resolveModel($identifier);

            if (!$model) {
                return;
            }

            // Only proceed when the model exposes the HasLockout trait helpers.
            // This keeps the listener simple: we rely on trait methods
            // (`hasActiveLock()` and `lock()`) and avoid managing many cases.
            if (!method_exists($model, 'hasActiveLock') || !method_exists($model, 'lock')) {
                return;
            }

            if ($model->hasActiveLock()) {
                return;
            }

            $model->lock([
                'expires_at' => $this->getExpiresAtAttribute(),
            ]);
        } catch (Throwable $_) {
            // Never allow exceptions to bubble out of this listener.
        }
    }

    /**
     * Resolve the login model for the given identifier.
     *
     * Returns null on failure or if the model cannot be resolved.
     */
    protected function resolveModel(mixed $identifier): ?Model
    {
        try {
            return Lockout::getLoginModel($identifier);
        } catch (Throwable $_) {
            return null;
        }
    }

    protected function getExpiresAtAttribute()
    {
        $autoHours = (int) config('lockout.auto_unlock_hours', 0);

        return $autoHours > 0 ? now()->addHours($autoHours) : null;
    }
}
