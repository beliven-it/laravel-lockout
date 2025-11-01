<?php

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout;
use Beliven\Lockout\Models\ModelLockout;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * unlockModel should return null when the lock's save() returns false.
 * Also ensure clearAttempts is invoked (the method is executed even when save() returns false).
 */
it('unlockModel returns null when lock save() returns false and still calls clearAttempts', function () {
    // Bind a Lockout subclass to intercept clearAttempts calls
    app()->bind(Lockout::class, function () {
        return new class extends \Beliven\Lockout\Lockout
        {
            public bool $clearCalled = false;

            public function __construct()
            {
                parent::__construct();
            }

            public function clearAttempts(string $id): void
            {
                $this->clearCalled = true;
            }
        };
    });

    /** @var Lockout $service */
    $service = app(Lockout::class);

    // Prepare a lock-like ModelLockout whose save() returns false
    $badLock = new class extends ModelLockout
    {
        public $unlocked_at;

        public $meta;

        public $reason;

        public function save(array $options = [])
        {
            return false;
        }
    };

    // Model that exposes configured login field and returns the bad lock
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public $email = 'notsaved@example.test';

        public $returnedLock;

        public function activeLock()
        {
            return $this->returnedLock;
        }
    };

    $model->returnedLock = $badLock;

    // Seed an attempts counter so clearAttempts would be meaningful (not strictly necessary)
    $service->incrementAttempts($model->email);
    expect($service->getAttempts($model->email))->toBe(1);

    $result = $service->unlockModel($model, []);

    // save() returned false so unlockModel should return null
    expect($result)->toBeNull();

    // Ensure clearAttempts was invoked on the bound service instance
    expect($service->clearCalled)->toBeTrue();
});

/**
 * unlockModel should swallow exceptions thrown during event dispatch.
 * We simulate an event dispatcher that throws when dispatch() is called.
 */
it('unlockModel swallows exceptions from event dispatch and still returns the lock when save succeeds', function () {
    /** @var Lockout $service */
    $service = app(Lockout::class);

    // Prepare a lock-like ModelLockout whose save() returns true
    $goodLock = new class extends ModelLockout
    {
        public $unlocked_at;

        public $meta;

        public $reason;

        public function save(array $options = [])
        {
            return true;
        }
    };

    // Model that exposes configured login field and returns the good lock
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public $email = 'eventthrow@example.test';

        public $returnedLock;

        public function activeLock()
        {
            return $this->returnedLock;
        }
    };

    $model->returnedLock = $goodLock;

    // Put some attempts to exercise clearAttempts (which should run and not throw)
    $service->incrementAttempts($model->email);
    expect($service->getAttempts($model->email))->toBe(1);

    // Replace the global event dispatcher resolved by the event() helper to simulate throwing
    $mockDispatcher = Mockery::mock();
    $mockDispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('simulated-dispatch-error'));
    // Bind into container as 'events' so event() helper uses this dispatcher
    app()->instance('events', $mockDispatcher);

    // Call unlockModel: even though dispatcher will throw, the method should swallow and return the lock
    $result = $service->unlockModel($model, ['requestData' => (object) ['ip' => '127.0.0.1']]);

    expect($result)->toBe($goodLock);

    // After the call, attempts clearing was attempted but may have failed; at minimum no exception bubbled.
});

/**
 * MarkModelAsLocked::resolveModel should swallow exceptions thrown by Lockout::getLoginModel.
 * We simulate the Lockout service throwing from getLoginModel and ensure listener.handle() doesn't throw.
 */
it('MarkModelAsLocked handles resolveModel throwing and does not propagate exceptions', function () {
    $identifier = 'resolver-error@example.test';

    // Mock the Lockout service so getLoginModel throws
    $mockService = Mockery::mock(Lockout::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->andThrow(new \RuntimeException('resolver-boom'));
    app()->instance(Lockout::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new EntityLocked($identifier, (object) ['ip' => '127.0.0.1']);

    // This should not throw despite the underlying resolver throwing
    $listener->handle($event);

    // If we reached here, the exception was swallowed as intended
    expect(true)->toBeTrue();
});
