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
        'account_logged' => [
            'subject' => 'Successful Login Notification',
            'line1'   => 'Your account with identifier :identifier has successfully logged in.',
            'line2'   => 'If this was not you, please lock your account immediatly.',
            'action'  => 'Lock account',
            'footer'  => 'Thank you for using our application!',
        ],
    ],
    'middleware' => [
        'account_locked' => 'Your account is currently locked due to multiple failed login attempts.',
    ],
    'controller' => [
        'account_unlocked' => 'Your account has been unlocked. You can now log in.',
        'account_locked'   => 'Your account has been locked.',
        'model_not_found'  => 'Account not found.',
        'general_error'    => 'An error occurred while processing your request. Please try again later.',
    ],
];
