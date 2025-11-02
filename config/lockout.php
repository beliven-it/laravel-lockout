<?php

// config for Beliven/Lockout
// This file contains configuration options used by the Lockout package.
// Each option includes an environment variable override (if applicable),
// the default value, and a short description of its effect.

return [

    /*
    |--------------------------------------------------------------------------
    | Login field
    |--------------------------------------------------------------------------
    |
    | 'login_field' (string)
    | Env: LOCKOUT_LOGIN_FIELD
    | Default: 'email'
    |
    | The attribute on your authenticatable model used to identify accounts
    | for lockout checks. Examples: 'email', 'username'. This value is used
    | when the package needs to look up a model by the supplied credential.
    |
    */
    'login_field' => env('LOCKOUT_LOGIN_FIELD', 'email'),

    /*
    |--------------------------------------------------------------------------
    | Unlock via notification
    |--------------------------------------------------------------------------
    |
    | 'unlock_via_notification' (bool)
    | Env: LOCKOUT_UNLOCK_VIA_NOTIFICATION
    | Default: true
    |
    | When true the package will attempt to send a notification when an
    | account becomes locked. The notification may optionally include a
    | way to unlock the account (for instance a link or token). Set to
    | false to disable any unlock-related behavior in notifications.
    |
    */
    'unlock_via_notification' => env('LOCKOUT_UNLOCK_VIA_NOTIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Unlock link expiry (minutes)
    |--------------------------------------------------------------------------
    |
    | 'unlock_link_minutes' (int)
    | Env: LOCKOUT_UNLOCK_LINK_MINUTES
    | Default: 1440
    |
    | Duration, in minutes, for the temporary signed unlock URL generated
    | by the package. The default value of 1440 corresponds to 24 hours.
    | Adjust this value to increase/decrease the signed URL lifetime.
    |
    */
    'unlock_link_minutes' => (int) env('LOCKOUT_UNLOCK_LINK_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Unlock redirect route
    |--------------------------------------------------------------------------
    |
    | 'unlock_redirect_route' (string)
    | Env: LOCKOUT_UNLOCK_REDIRECT_ROUTE
    | Default: 'login'
    |
    | The named route used to redirect users after a successful unlock action
    | or when the package cannot resolve a model for the provided identifier.
    | This defaults to the common 'login' route but can be overridden to any
    | named route in the host application.
    |
    */
    'unlock_redirect_route' => env('LOCKOUT_UNLOCK_REDIRECT_ROUTE', 'login'),

    /*
    |--------------------------------------------------------------------------
    | Notification class
    |--------------------------------------------------------------------------
    |
    | 'notification_class' (string - FQCN)
    | Env: LOCKOUT_NOTIFICATION_CLASS
    | Default: \Beliven\Lockout\Notifications\AccountLocked::class
    |
    | The Notification class instantiated to notify users about account locks.
    | Must extend Illuminate\Notifications\Notification and support the
    | channels configured below. You can replace it with your own class
    | to customize notification content or behavior.
    |
    */
    'notification_class' => env(
        'LOCKOUT_NOTIFICATION_CLASS',
        \Beliven\Lockout\Notifications\AccountLocked::class
    ),

    /*
    |--------------------------------------------------------------------------
    | Notification channels
    |--------------------------------------------------------------------------
    |
    | 'notification_channels' (array|string)
    | Env: LOCKOUT_NOTIFICATION_CHANNELS
    | Default: ['mail']
    |
    | The channels used when sending the lock notification (e.g. 'mail',
    | 'database', custom channel names). If supplying via env, provide a
    | format your loader understands (e.g. JSON) and convert to array.
    |
    */
    'notification_channels' => env('LOCKOUT_NOTIFICATION_CHANNELS', ['mail']),

    /*
    |--------------------------------------------------------------------------
    | Maximum attempts
    |--------------------------------------------------------------------------
    |
    | 'max_attempts' (int)
    | Env: LOCKOUT_MAX_ATTEMPTS
    | Default: 5
    |
    | The number of failed authentication attempts allowed before a persistent
    | lockout is created for the account. Tweak to make the lockout policy
    | stricter or more permissive.
    |
    */
    'max_attempts' => env('LOCKOUT_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Decay (minutes)
    |--------------------------------------------------------------------------
    |
    | 'decay_minutes' (int)
    | Env: LOCKOUT_DECAY_MINUTES
    | Default: 30
    |
    | Time window (in minutes) used for throttling counters / temporary
    | rate-limiting. This is typically used with transient cache-based
    | counters to determine how long a rapid sequence of failures should
    | be considered when counting attempts.
    |
    */
    'decay_minutes' => env('LOCKOUT_DECAY_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | 'cache_store' (string)
    | Env: LOCKOUT_CACHE_STORE
    | Default: 'database'
    |
    | The cache store name (as defined in config/cache.php) used by the
    | package for throttling counters and ephemeral data. Examples: 'redis',
    | 'file', 'database'. Ensure the chosen store is configured and suitable
    | for the app's concurrency/profile.
    |
    */
    'cache_store' => env('LOCKOUT_CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Automatic unlock (expiry)
    |--------------------------------------------------------------------------
    |
    | 'auto_unlock_hours' (int)
    | Env: LOCKOUT_AUTO_UNLOCK_HOURS
    | Default: 0
    |
    | Controls automatic expiry of persistent model locks (the `expires_at`
    | column on the model_lockouts table).
    |
    | Behavior:
    |  - If > 0: when creating a persistent lock, the lock's `expires_at`
    |    will be set to now()->addHours(auto_unlock_hours). After that time
    |    the lock is considered inactive by the package (scopeActive/isActive).
    |  - If 0: no automatic expiry is set; `expires_at` will be null and the
    |    lock requires manual unlocking (setting `unlocked_at`).
    |
    | Note:
    |  - Expiry does not automatically set `unlocked_at`. Expired locks are
    |    simply treated as inactive. If you need `unlocked_at` populated for
    |    expired items, handle it via a listener/command or during pruning.
    |
    */
    'auto_unlock_hours' => (int) env('LOCKOUT_AUTO_UNLOCK_HOURS', 1),

    /*
    |--------------------------------------------------------------------------
    | Pruning / Retention
    |--------------------------------------------------------------------------
    |
    | 'prune' (array)
    | Controls automatic pruning/cleanup thresholds. Values are in days.
    | The package can expose an artisan command or scheduled job to execute
    | pruning using these settings.
    |
    */
    'prune' => [
        /*
        |----------------------------------------------------------------------
        | Prune enabled
        |----------------------------------------------------------------------
        |
        | 'enabled' (bool)
        | Env: LOCKOUT_PRUNE_ENABLED
        | Default: true
        |
        | Toggle pruning behavior. When false, any built-in prune command will
        | be disabled / be a no-op.
        |
        */
        'enabled' => env('LOCKOUT_PRUNE_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Lockout logs retention
        |----------------------------------------------------------------------
        |
        | 'lockout_logs_days' (int)
        | Env: LOCKOUT_PRUNE_LOGS_DAYS
        | Default: 90
        |
        | Number of days to retain entries in the `lockout_logs` table. Records
        | older than this will be removed by the prune routine.
        |
        */
        'lockout_logs_days' => env('LOCKOUT_PRUNE_LOGS_DAYS', 90),

        /*
        |----------------------------------------------------------------------
        | Model lockouts retention
        |----------------------------------------------------------------------
        |
        | 'model_lockouts_days' (int)
        | Env: LOCKOUT_PRUNE_MODEL_LOCKOUTS_DAYS
        | Default: 365
        |
        | Number of days to retain entries in the `model_lockouts` table (the
        | history of persistent locks). The prune routine considers both rows
        | explicitly unlocked (unlocked_at set) as well as rows that have
        | expired via `expires_at`.
        |
        */
        'model_lockouts_days' => env('LOCKOUT_PRUNE_MODEL_LOCKOUTS_DAYS', 365),
    ],
];
