<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Auth\Events\Failed;

class RecordFailedLoginAttempt
{
    /**
     * Handle the event.
     *
     * This listener records a failed authentication attempt by delegating to
     * the Lockout service. It ensures that attempts are only incremented when
     * authentication actually fails.
     */
    public function handle(Failed $event): void
    {
        $identifierField = Lockout::getLoginField();

        // Credentials may not always contain the identifier field
        $identifier = null;
        if (is_array($event->credentials) && array_key_exists($identifierField, $event->credentials)) {
            $identifier = (string) $event->credentials[$identifierField];
        }

        if (!$identifier) {
            return;
        }

        // Build minimal metadata for logging/notification
        $data = (object) [
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        try {
            // attemptLockout will increment attempts, create a log entry and
            // dispatch EntityLocked when the threshold is reached.
            Lockout::attemptLockout($identifier, $data);
        } catch (\Throwable $e) {
            // Swallow exceptions to avoid breaking the authentication flow.
            // The package should not prevent authentication processing if the
            // lockout service has an unexpected error.
            // Consider logging this in the host application if desired.
        }
    }
}
