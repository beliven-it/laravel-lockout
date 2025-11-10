<?php

use Beliven\Lockout\Listeners\OnUserLogin;
use Beliven\Lockout\Tests\Support\LockableModelStub;
use Illuminate\Auth\Events\Login;

describe('OnUserLogin listener', function () {
    afterEach(function () {
        if (function_exists('\Mockery::close')) {
            \Mockery::close();
        }
    });

    it('does nothing when logout_on_login is disabled', function () {
        // Ensure the feature toggle is off.
        config()->set('lockout.logout_on_login', false);

        // Create a model that is locked but records whether logoutOnLockout was invoked.
        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $logoutCalled = false;

            public function isLockedOut(): bool
            {
                return true;
            }

            public function logoutOnLockout(?string $guard = null): bool
            {
                $this->logoutCalled = true;

                return true;
            }

            // Minimal Authenticatable implementation so this stub can be used with Login events.
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        $listener = new OnUserLogin;

        // Create a fake Login event that contains the user and a guard property.
        $event = new class($user, 'web') extends Login
        {
            public $user;

            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        // Handle the event - since the config disables the feature the model should not be called.
        $listener->handle($event);

        expect($user->logoutCalled)->toBeFalse();
    });

    it('calls logoutOnLockout with guard when enabled and user locked', function () {
        // Enable the automatic logout feature.
        config()->set('lockout.logout_on_login', true);

        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $receivedGuard = null;

            public function isLockedOut(): bool
            {
                return true;
            }

            public function logoutOnLockout(?string $guard = null): bool
            {
                $this->receivedGuard = $guard;

                return true;
            }

            // Minimal Authenticatable implementation
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        $listener = new OnUserLogin;

        // Provide an event that includes a guard name; the listener should forward it.
        $event = new class($user, 'api') extends Login
        {
            public $user;

            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        $listener->handle($event);

        expect($user->receivedGuard)->toBe('api');
    });

    it('does not call logoutOnLockout when user is not locked', function () {
        config()->set('lockout.logout_on_login', true);

        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $logoutCalled = false;

            public function isLockedOut(): bool
            {
                return false;
            }

            public function logoutOnLockout(?string $guard = null): bool
            {
                $this->logoutCalled = true;

                return true;
            }

            // Minimal Authenticatable implementation
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        $listener = new OnUserLogin;

        $event = new class($user, 'web') extends Login
        {
            public $user;

            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        $listener->handle($event);

        expect($user->logoutCalled)->toBeFalse();
    });

    it('still delegates to model when session is not started (simulating worker/console)', function () {
        // Enable the feature.
        config()->set('lockout.logout_on_login', true);

        // Ensure session is not erroneously started for this test (best-effort check).
        // Many test environments won't have a started session by default; this assertion
        // is non-fatal — the main goal is to verify the delegation path works regardless.
        if (function_exists('session')) {
            // If session exists, try to ensure it's not started. If it is started, we
            // still run the test — the listener conditions will still call the method.
            try {
                if (session()->isStarted()) {
                    session()->invalidate();
                }
            } catch (\Throwable $_) {
                // Ignore session manipulation errors in CI environments.
            }
        }

        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $receivedGuard = null;

            public $logoutCalled = false;

            public function isLockedOut(): bool
            {
                return true;
            }

            public function logoutOnLockout(?string $guard = null): bool
            {
                $this->receivedGuard = $guard;
                $this->logoutCalled = true;

                return true;
            }

            // Minimal Authenticatable implementation
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        $listener = new OnUserLogin;

        // Simulate an event where guard is provided, but we're in a non-HTTP-like state.
        $event = new class($user, 'web') extends Login
        {
            public $user;

            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        // Should not throw and should delegate to the model's hook.
        $listener->handle($event);

        expect($user->logoutCalled)->toBeTrue();
        expect($user->receivedGuard)->toBe('web');
    });

    it('does nothing when configured login_field is missing on the model', function () {
        // Enable login notification feature
        config()->set('lockout.lock_on_login', true);
        // Ensure default login field
        config()->set('lockout.login_field', 'email');

        // Create a model that intentionally lacks the configured identifier (no email)
        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $remember_token = null;

            // Minimal Authenticatable implementation (no email property)
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        $listener = new OnUserLogin;

        // Provide an event that includes a guard name.
        $event = new class($user, 'web') extends Login
        {
            /** @var \Illuminate\Contracts\Auth\Authenticatable */
            public $user;

            /** @var string|null */
            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        // Should not throw and should simply return early (no attempt to send notification).
        try {
            $listener->handle($event);
            $reached = true;
        } catch (\Throwable $e) {
            $reached = false;
        }

        expect($reached)->toBeTrue();
    });

    it('delegates to Lockout::attemptSendLoginNotification with configured login_field', function () {
        // Enable feature and set a custom login field
        config()->set('lockout.lock_on_login', true);
        config()->set('lockout.login_field', 'username');

        // Create a model that exposes the custom login field
        $user = new class extends LockableModelStub implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public $username = 'alice';

            public $remember_token = null;

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id ?? 1;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return $this->remember_token ?? null;
            }

            public function setRememberToken($value)
            {
                $this->remember_token = $value;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };

        // Mock the Lockout service in the container so the facade resolves to it.
        $mock = Mockery::mock(\Beliven\Lockout\Lockout::class);
        // The listener calls Lockout::getLoginField() before delegating — ensure the mock responds.
        $mock->shouldReceive('getLoginField')->andReturn('username');
        $mock->shouldReceive('attemptSendLoginNotification')->once()->with(
            Mockery::on(function ($arg) {
                return is_object($arg) && isset($arg->username) && $arg->username === 'alice';
            }),
            Mockery::any()
        );

        app()->instance(\Beliven\Lockout\Lockout::class, $mock);

        $listener = new OnUserLogin;

        $event = new class($user, 'web') extends Login
        {
            /** @var \Illuminate\Contracts\Auth\Authenticatable */
            public $user;

            /** @var string|null */
            public $guard;

            public function __construct($user, $guard = null)
            {
                $this->user = $user;
                $this->guard = $guard;
            }
        };

        // Execute listener; mock expectations verify delegation occurred.
        $listener->handle($event);

        expect(true)->toBeTrue();
    });
});
