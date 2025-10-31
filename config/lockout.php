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
];
