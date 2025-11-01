<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Lockout - Vendor Translations
    |--------------------------------------------------------------------------
    |
    | Default English translations loaded under the package namespace so
    | consumers may publish and override them via vendor:publish.
    |
    | Access example:
    |   trans('laravel-lockout::translations.notifications.account_locked.subject')
    |   trans('laravel-lockout::translations.middleware.account_locked')
    |
    | Placeholders:
    |  - :identifier => the locked identifier (e.g. email)
    |  - :minutes    => lockout duration in minutes
    |
    */

    'notifications' => [
        'account_locked' => [
            'subject' => 'Account Locked Due to Multiple Failed Login Attempts',
            'line1'   => 'Your account with identifier :identifier has been locked due to multiple failed login attempts.',
            'line2'   => 'The lockout will last for :minutes minutes.',
            'action'  => 'Reset Your Password',
            'footer'  => 'If you did not attempt to log in, please contact support immediately.',
        ],
    ],

    'middleware' => [
        // Message returned by the EnsureUserIsNotLocked middleware when the account
        // is currently locked (HTTP 429).
        'account_locked' => 'Account locked due to too many login attempts',
    ],
];
