<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Auth\Events\Login;

class OnUserLogin
{
    public function handle(Login $event): void
    {
        $this->tryLogoutOnLogin($event);
        $this->tryNotifyOnLogin($event);
    }

    protected function tryNotifyOnLogin(Login $event): void
    {
        // PHPStan/Psalm type assertion:
        /** @var \Beliven\Lockout\Contracts\LockableModel $user */
        $user = $event->user;

        // Get identifier
        $identifierField = Lockout::getLoginField();
        if (!isset($user->{$identifierField})) {
            return;
        }

        $identifier = (string) $user->{$identifierField};
        Lockout::attemptSendLoginNotification($identifier, (object) []);
    }

    protected function tryLogoutOnLogin(Login $event): void
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
