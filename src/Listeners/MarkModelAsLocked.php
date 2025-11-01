<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Lockout as LockoutService;
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

            // Resolve the Lockout service and model for the identifier.
            $lockout = app(LockoutService::class);
            $model = $lockout->getLoginModel($identifier);

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
            if (method_exists($model, 'lockouts')) {
                try {
                    $model->lockouts()->create([
                        'locked_at' => now(),
                    ]);
                } catch (Throwable $_) {
                    // Relation-based creation failed; swallow to keep flow resilient.
                }
            }
        } catch (Throwable $_) {
            // Never allow exceptions to bubble out of this listener.
        }
    }
}
