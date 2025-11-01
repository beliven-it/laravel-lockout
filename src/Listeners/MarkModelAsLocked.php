<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Facades\Lockout;
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

            // Resolve the concrete model via the Lockout facade (single-model strategy).
            try {
                $model = Lockout::getLoginModel($identifier);
            } catch (Throwable $_) {
                $model = null;
            }

            if (!$model) {
                return;
            }

            // Prefer model-provided lock logic when available.
            if (method_exists($model, 'lock')) {
                try {
                    $model->lock();
                } catch (Throwable $_) {
                    // If model->lock() throws, swallow and avoid breaking the app.
                }

                return;
            }

            // Otherwise, attempt to create a lock record via the model's relation.
            // Avoid creating duplicate active lock records by checking for an existing active lock
            // before creating a new one.
            if (method_exists($model, 'lockouts')) {
                try {
                    $hasActive = false;

                    // Prefer model-provided activeLock() helper if available.
                    if (method_exists($model, 'activeLock')) {
                        try {
                            $hasActive = (bool) $model->activeLock();
                        } catch (Throwable $_) {
                            $hasActive = false;
                        }
                    } else {
                        // Fallback: query the relation for an active lock.
                        try {
                            $hasActive = (bool) $model->lockouts()
                                ->whereNull('unlocked_at')
                                ->where(function ($q) {
                                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                })
                                ->exists();
                        } catch (Throwable $_) {
                            $hasActive = false;
                        }
                    }

                    if (!$hasActive) {
                        // Determine expiry based on configuration. If auto_unlock_hours is 0,
                        // we store a null expires_at (manual unlock). Otherwise compute expiry.
                        $autoHours = (int) config('lockout.auto_unlock_hours', 0);
                        $attributes = [
                            'locked_at' => now(),
                        ];
                        if ($autoHours > 0) {
                            $attributes['expires_at'] = now()->addHours($autoHours);
                        } else {
                            $attributes['expires_at'] = null;
                        }

                        $model->lockouts()->create($attributes);
                    }
                } catch (Throwable $_) {
                    // Relation-based creation failed; swallow to keep flow resilient.
                }
            }
        } catch (Throwable $_) {
            // Never allow exceptions to bubble out of this listener.
        }
    }
}
