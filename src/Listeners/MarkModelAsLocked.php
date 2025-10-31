<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Throwable;

class MarkModelAsLocked
{
    /**
     * Handle the event.
     *
     * When an EntityLocked event is dispatched it means the identifier reached
     * the configured attempt threshold. This listener will mark the corresponding
     * model's `blocked_at` timestamp (using the model's `lock()` method if
     * available, otherwise updating the column directly).
     */
    public function handle(EntityLocked $event): void
    {
        try {
            // The EntityLocked event exposes the identifier property.
            $identifier = $event->identifier;

            // Resolve the Lockout service from the container to access instance methods.
            $lockout = app(LockoutService::class);

            // Try to resolve the model using the Lockout service convenience method.
            $model = $lockout->getLoginModel($identifier);

            if (!$model) {
                // Nothing to mark (no persistent model found for the identifier).
                return;
            }

            // If the model exposes a `lock()` method (for example via the HasLockout trait),
            // prefer calling it so the model can encapsulate its own locking logic.
            // Use method_exists at runtime; silence phpstan about the model type here.
            // @phpstan-ignore-next-line
            if (method_exists($model, 'lock')) {
                $model->lock();

                return;
            }

            // Otherwise, set the `blocked_at` timestamp and persist.
            // If the model class declares a public `blocked_at` property, set it directly
            // to keep the intent clear; otherwise fall back to setAttribute to avoid
            // property-access warnings from static analyzers and support Eloquent attribute handling.
            if (property_exists($model, 'blocked_at')) {
                $model->blocked_at = now();
            } else {
                $model->setAttribute('blocked_at', now());
            }
            $model->save();
        } catch (Throwable $e) {
            // Never let exceptions break the flow of the application.
            // Swallowing here keeps the lockout flow resilient. Host apps may
            // choose to log this if they want visibility into failures.
        }
    }
}
