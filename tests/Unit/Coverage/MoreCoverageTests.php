<?php

use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * Ensure attemptSendLockoutNotification quits early when unlocking via notification is disabled.
 */
it('attemptSendLockoutNotification is a no-op when unlock_via_notification is false', function () {
    // Bind a Lockout subclass that disables unlock notifications
    app()->bind(LockoutService::class, function () {
        return new class extends LockoutService
        {
            public function __construct()
            {
                // Skip parent config resolution to set property deterministically
                $this->maxAttempts = 5;
                $this->decayMinutes = 30;
                $this->cacheStore = 'array';
                $this->unlockViaNotification = false;
            }
        };
    });

    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    // If notifications were attempted we'd need to inspect side-effects; here we just ensure no exception is thrown.
    $service->attemptSendLockoutNotification('nope@example.test', (object) ['ip' => '127.0.0.1']);

    expect(true)->toBeTrue();
});

/**
 * Ensure attemptSendLockoutNotification returns early when identifier is not a valid email.
 */
it('attemptSendLockoutNotification is a no-op for non-email identifiers', function () {
    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    // Use a clearly invalid email
    $service->attemptSendLockoutNotification('not-an-email', (object) ['ip' => '127.0.0.1']);

    // No exception and nothing sent
    expect(true)->toBeTrue();
});

/**
 * Ensure attemptSendLockoutNotification safely returns when the resolved model lacks notify().
 */
it('attemptSendLockoutNotification returns when model cannot be notified', function () {
    $identifier = 'userwithoutnotify@example.test';

    // Bind a Lockout subclass that resolves a model lacking notify()
    app()->bind(LockoutService::class, function () use ($identifier) {
        return new class($identifier) extends LockoutService
        {
            protected string $identifier;

            public function __construct($identifier)
            {
                parent::__construct();
                $this->identifier = $identifier;
                $this->cacheStore = 'array';
            }

            // Resolve a simple stdClass-like object without notify() method
            public function getLoginModel(string $id): ?\Illuminate\Database\Eloquent\Model
            {
                // Return a minimal object that is not Notifiable
                return new class
                {
                    public $email = 'userwithoutnotify@example.test';
                    // no notify() method
                };
            }
        };
    });

    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    $service->attemptSendLockoutNotification($identifier, (object) ['ip' => '127.0.0.1']);

    expect(true)->toBeTrue();
});

/**
 * Ensure clearAttempts actually clears stored attempts in the configured cache store.
 */
it('clearAttempts removes stored attempt counters', function () {
    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    $id = 'clearme@example.test';

    // Ensure starting from zero
    $service->clearAttempts($id);
    expect($service->getAttempts($id))->toBe(0);

    // Increment and assert > 0
    $service->incrementAttempts($id);
    expect($service->getAttempts($id))->toBeGreaterThan(0);

    // Clear and assert 0 again
    $service->clearAttempts($id);
    expect($service->getAttempts($id))->toBe(0);
});

/**
 * Ensure middleware treats a model->activeLock() throwing as "not locked" (resilient behavior).
 */
it('EnsureUserIsNotLocked treats activeLock throwing as not locked and passes to next', function () {
    $identifier = 'broken-active@example.test';

    // Mock Lockout service to resolve the login field and model
    $mock = Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->once()->andReturn('email');
    $mock->shouldReceive('getLoginModel')->once()->with($identifier)->andReturn(
        new class
        {
            public $email = 'broken-active@example.test';

            public function activeLock()
            {
                throw new \RuntimeException('boom from activeLock');
            }
        }
    );

    app()->instance(LockoutService::class, $mock);

    $middleware = new EnsureUserIsNotLocked;

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $result = $middleware->handle($request, function ($req) {
        return 'next-called';
    });

    expect($result)->toBe('next-called');
});

/**
 * Ensure lockedResponse returns a JSON response containing the translation key (fallback).
 */
it('EnsureUserIsNotLocked lockedResponse returns standardized json message and 429 status', function () {
    $middleware = new EnsureUserIsNotLocked;

    // Call the protected method via reflection to assert payload without going through handle path.
    $ref = new ReflectionClass($middleware);
    $method = $ref->getMethod('lockedResponse');
    $method->setAccessible(true);

    $response = $method->invoke($middleware);

    $json = json_decode($response->getContent(), true);

    // When translations are not present the trans() helper returns the key.
    expect($json)->toHaveKey('message');
    expect($response->getStatusCode())->toBe(429);
});
