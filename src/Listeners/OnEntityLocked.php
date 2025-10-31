<?php

namespace Beliven\Lockout\Listeners;

use Beliven\Lockout\Facades\Lockout;

class OnEntityLocked
{
    public function handle(object $event): void
    {
        // Implement your logic to handle the entity locked event here.
        // You can access event properties like $event->identifier and $event->requestData
        Lockout::attemptSendLockoutNotification($event->identifier, $event->requestData);
    }
}
