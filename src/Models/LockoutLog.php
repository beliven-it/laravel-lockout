<?php

namespace Beliven\Lockout\Models;

use Illuminate\Database\Eloquent\Model;

class LockoutLog extends Model
{
    protected $table = 'lockout_logs';

    protected $casts = [
        'attempted_at' => 'datetime',
    ];

    public $timestamps = false;
}
