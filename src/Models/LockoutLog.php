<?php

namespace Beliven\Lockout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lockout log entry.
 *
 * @property int $id
 * @property string|null $identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $attempted_at
 * @property \Illuminate\Database\Eloquent\Model|null $model The related model (morph)
 */
class LockoutLog extends Model
{
    protected $table = 'lockout_logs';

    protected $casts = [
        'attempted_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Morph relation to the associated model (if available).
     *
     * This allows associating a log entry to the concrete Eloquent model
     * instance (for example a User) using a nullable morph column.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
