<?php

// config for Beliven/Lockout
return [
    'login_field'             => env('LOCKOUT_LOGIN_FIELD', 'email'),
    'unlock_via_notification' => env('LOCKOUT_UNLOCK_VIA_NOTIFICATION', true),
    'notification_class'      => env('LOCKOUT_NOTIFICATION_CLASS', \Beliven\Lockout\Notifications\AccountLocked::class),
    'notification_channels'   => env('LOCKOUT_NOTIFICATION_CHANNELS', ['mail']),
    'max_attempts'            => env('LOCKOUT_MAX_ATTEMPTS', 5),
    'decay_minutes'           => env('LOCKOUT_DECAY_MINUTES', 30),
    'cache_store'             => env('LOCKOUT_CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Automatic unlock (expiry)
    |--------------------------------------------------------------------------
    |
    | Control automatic expiry of persistent model locks (the `expires_at` column).
    |
    | - 'auto_unlock_hours' (int): number of hours after which a created
    |   persistent lock will expire automatically. When creating a lock, if this
    |   value is > 0 the lock's `expires_at` will be set to now()->addHours(...).
    |   If set to 0 (the default), automatic expiry is disabled and `expires_at`
    |   will be stored as null (manual unlock only).
    |
    | Example:
    |   'auto_unlock_hours' => 24, // locks expire after 24 hours
    */
    'auto_unlock_hours' => (int) env('LOCKOUT_AUTO_UNLOCK_HOURS', 0),

    /*
    |--------------------------------------------------------------------------
    | Pruning / Retention
    |--------------------------------------------------------------------------
    |
    | These options control automatic pruning of old records. The package may
    | provide an artisan command / scheduled job to remove old entries from the
    | `lockout_logs` and `model_lockouts` tables. Values are expressed in days.
    |
    */
    'prune' => [
        // toggle pruning behavior (default: enabled)
        'enabled' => env('LOCKOUT_PRUNE_ENABLED', true),

        // number of days to retain entries in the lockout_logs table
        'lockout_logs_days' => env('LOCKOUT_PRUNE_LOGS_DAYS', 90),

        // number of days to retain entries in the model_lockouts table (history)
        'model_lockouts_days' => env('LOCKOUT_PRUNE_MODEL_LOCKOUTS_DAYS', 365),
    ],
];
