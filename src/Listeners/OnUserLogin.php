<?php

namespace Beliven\Lockout\Listeners;

use Illuminate\Auth\Events\Login;

class OnUserLogin
{
    public function handle(Login $event): void
    {
        // Check if the automatic logout feature is enabled.
        $logoutOnLogin = config('lockout.logout_on_login', false);
        if (!$logoutOnLogin) {
            return;
        }

        // PHPStan/Psalm type assertion:
        /** @var \Beliven\Lockout\Contracts\LockableModel $user */
        $user = $event->user;

        if (!$user->isLockedOut()) {
            return;
        }

        // Call the model-provided handler with the guard context.
        $user->logoutOnLockout($event->guard);
    }
}
