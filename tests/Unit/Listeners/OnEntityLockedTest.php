<?php

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Listeners\OnEntityLocked;
use Beliven\Lockout\Listeners\RecordFailedLoginAttempt;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Auth\Events\Failed;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

it('calls Lockout::attemptSendLockoutNotification when an EntityLocked event is handled', function () {
    $identifier = 'notify@example.test';
    $payload = (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit'];

    $mock = Mockery::mock(LockoutService::class);
    $mock->shouldReceive('attemptSendLockoutNotification')
        ->once()
        ->withArgs(function ($id, $data) use ($identifier) {
            return $id === $identifier
                && is_object($data)
                && ($data->ip ?? null) === '127.0.0.1';
        });

    app()->instance(LockoutService::class, $mock);

    $listener = new OnEntityLocked;
    $event = new EntityLocked($identifier, $payload);

    // Execute; expectations on the mock will validate delegation occurred.
    $listener->handle($event);

    // If we reach here without Mockery complaints, behavior is correct.
    expect(true)->toBeTrue();
});

it('swallows exceptions thrown from Lockout::attemptLockout in RecordFailedLoginAttempt', function () {
    $identifier = 'errorcase@example.test';

    $mock = Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->once()->andReturn('email');
    $mock->shouldReceive('attemptLockout')->once()->andThrow(new \RuntimeException('simulated-failure'));

    app()->instance(LockoutService::class, $mock);

    // Ensure request helpers won't error when building metadata
    request()->server->set('REMOTE_ADDR', '127.0.0.1');
    request()->headers->set('User-Agent', 'phpunit');

    $event = new Failed('web', null, ['email' => $identifier]);

    $listener = new RecordFailedLoginAttempt;

    // The listener should not propagate the exception; it should swallow it.
    try {
        $listener->handle($event);
        $reached = true;
    } catch (\Throwable $e) {
        $reached = false;
    }

    expect($reached)->toBeTrue();
});
