<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lockout Notification Translations
    |--------------------------------------------------------------------------
    |
    | Default English translations for the AccountLocked notification used by
    | the package. Package consumers can publish and override these keys to
    | customize the email text shown to locked users.
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
            'action'  => 'Unlock account',
            'footer'  => 'If you did not attempt to log in, please contact support immediately.',
        ],
    ],
    'middleware' => [
        'account_locked' => 'Your account is currently locked due to multiple failed login attempts.',
    ],
    'controller' => [
        'account_unlocked' => 'Your account has been unlocked. You can now log in.',
        'model_not_found'  => 'Account not found.',
    ],
];
