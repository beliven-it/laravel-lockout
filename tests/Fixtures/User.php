<?php

namespace Beliven\Lockout\Tests\Fixtures;

use Beliven\Lockout\Traits\HasLockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * Simple User fixture for tests.
 *
 * This lightweight model provides the minimal surface required by the package
 * tests: a `blocked_at` timestamp, the `HasLockout` convenience trait, and
 * the `Notifiable` trait so notifications (if exercised) don't error.
 */
class User extends Model
{
    use HasLockout, Notifiable;

    protected $table = 'users';

    // Allow mass assignment for tests convenience
    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'blocked_at' => 'datetime',
    ];
}
