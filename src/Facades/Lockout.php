<?php

namespace Beliven\Lockout\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Beliven\Lockout\Lockout
 *
 * @method static int getAttempts(string $id)
 * @method static void incrementAttempts(string $id)
 * @method static bool hasTooManyAttempts(string $id)
 * @method static bool attemptLockout(string $id, object $data)
 * @method static void attemptSendLockoutNotification(string $id, object $data)
 * @method static string getLoginField()
 */
class Lockout extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Beliven\Lockout\Lockout::class;
    }
}
