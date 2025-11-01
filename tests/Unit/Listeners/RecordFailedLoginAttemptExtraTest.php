<?php

use Beliven\Lockout\Listeners\RecordFailedLoginAttempt;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Auth\Events\Failed;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

it('does nothing when credentials do not contain the configured identifier field', function () {
    $identifierField = 'email';

    // Mock the Lockout service so getLoginField is called but attemptLockout must NOT be invoked.
    $mock = Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->once()->andReturn($identifierField);
    $mock->shouldNotReceive('attemptLockout');

    app()->instance(LockoutService::class, $mock);

    // Build a Failed event whose credentials do NOT include the configured identifier ('email').
    $event = new Failed('web', null, ['username' => 'no-email']);

    $listener = new RecordFailedLoginAttempt;

    // Should return early and not call attemptLockout
    $listener->handle($event);

    // If we reach here without Mockery complaining, the behavior is correct.
    expect(true)->toBeTrue();
});

it('calls Lockout::attemptLockout when credentials contain the identifier', function () {
    $identifierField = 'email';
    $identifierValue = 'present@example.test';

    // Prepare a Lockout mock that expects attemptLockout to be called once with the identifier
    $mock = Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->once()->andReturn($identifierField);

    // Expect attemptLockout to be called once with the identifier and an object payload
    $mock->shouldReceive('attemptLockout')->once()->withArgs(function ($id, $data) use ($identifierValue) {
        if ($id !== $identifierValue) {
            return false;
        }

        // The listener builds a stdClass with ip and user_agent properties.
        if (!is_object($data)) {
            return false;
        }

        // ip and user_agent may be null in the test environment; just ensure it's an object carrying something
        return property_exists($data, 'ip') && property_exists($data, 'user_agent');
    });

    app()->instance(LockoutService::class, $mock);

    // Ensure a request instance exists so request()->ip() and userAgent() are callable.
    // Creating a simple request provides these helpers in the testing environment.
    request()->server->set('REMOTE_ADDR', '127.0.0.1');
    request()->headers->set('User-Agent', 'phpunit');

    $event = new Failed('web', null, [$identifierField => $identifierValue]);

    $listener = new RecordFailedLoginAttempt;

    // Execute; expectation on mock will validate call occurred.
    $listener->handle($event);

    expect(true)->toBeTrue();
});
