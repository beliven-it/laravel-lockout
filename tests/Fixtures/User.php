<?php

namespace Beliven\Lockout\Tests\Fixtures;

use Beliven\Lockout\Contracts\LockableModel;
use Beliven\Lockout\Traits\HasLockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * Simple User fixture for tests.
 *
 * This lightweight model provides the minimal surface required by the package
 * tests: the `HasLockout` convenience trait and the `Notifiable` trait so notifications (if exercised) don't error.
 */
class User extends Model implements LockableModel
{
    use HasLockout, Notifiable;

    protected $table = 'users';

    // Allow mass assignment for tests convenience
    protected $guarded = [];

    public $timestamps = true;
}
