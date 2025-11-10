<?php

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Http\Controllers\LockController;
use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Beliven\Lockout\Models\LockoutLog;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Route;

describe('MarkModelAsLocked listener', function () {
    afterEach(function () {
        \Mockery::close();
    });

    it('calls lock() on the model when method exists', function () {
        $identifier = 'user-with-lock@example.test';

        // Create an Eloquent-like model that exposes a lock() method
        $model = new class extends Beliven\Lockout\Tests\Support\LockableModelStub
        {
            public $locked = false;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function lock(array $options = []): void
            {
                $this->locked = true;
            }

            public function hasActiveLock(): bool
            {
                return false;
            }
        };

        // Mock the Lockout service to return our model
        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

        // Bind mock into the container so the listener resolves it via app()
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) ['ip' => '127.0.0.1']);

        // Execute
        $listener->handle($event);

        // Assert the model's lock() was invoked
        expect($model->locked)->toBeTrue();
    });

    it('does nothing when no model is found for the identifier', function () {
        $identifier = 'nonexistent@example.test';

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturnNull();

        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        // Should not throw and should simply return
        $listener->handle($event);

        // Sanity check: reached end without exception
        expect(true)->toBeTrue();
    });

    it('swallows exceptions thrown while handling to avoid breaking the app', function () {
        $identifier = 'errorcase@example.test';

        // Create a model whose save method will throw
        $model = new class extends EloquentModel
        {
            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function save(array $options = []): bool
            {
                throw new \RuntimeException('save failed');
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        // The listener should not let exceptions bubble up; it swallows them.
        try {
            $listener->handle($event);
            $reached = true;
        } catch (\Throwable $e) {
            $reached = false;
        }

        expect($reached)->toBeTrue();
    });

    it('creates a persistent lock with expires_at when auto_unlock_hours > 0', function () {
        $identifier = 'expirecase@example.test';

        // Configure auto-unlock to 3 hours for this test
        config()->set('lockout.auto_unlock_hours', 3);

        // Model that captures created attributes similarly to the previous test
        $model = new class extends Beliven\Lockout\Tests\Support\LockableModelStub
        {
            public $locked = false;

            public $lastCreated = null;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function lock(array $options = []): void
            {
                $this->lastCreated = $options['expires_at'] ?? null;
                $this->locked = true;
            }

            public function hasActiveLock(): bool
            {
                return false;
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        // Execute the listener: it should create a lock and populate expires_at
        $listener->handle($event);

        // Ensure the create was invoked and attributes captured
        expect($model->lastCreated)->not->toBeNull();
    });

    it('stores null expires_at when auto_unlock_hours is 0 (manual unlock only)', function () {
        $identifier = 'noupcase@example.test';

        // Configure auto-unlock disabled
        config()->set('lockout.auto_unlock_hours', 0);

        // Model that captures created attributes similarly to the previous test
        $model = new class extends EloquentModel
        {
            public $locked = false;

            public $lastCreated = null;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function lock($options): void
            {
                $this->lastCreated = $options['expires_at'] ?? null;
                $this->locked = true;
            }

            public function hasActiveLock(): bool
            {
                return false;
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        $listener->handle($event);

        // Verify the created attributes exist and expires_at is explicitly null
        expect($model->lastCreated)->toBeNull();
    });

    // Extra cases to improve coverage

    it('does nothing when the model already has an active lock', function () {
        $identifier = 'already-locked@example.test';

        // Model whose hasActiveLock returns true and would set a flag if lock() called
        $model = new class extends Beliven\Lockout\Tests\Support\LockableModelStub
        {
            public $lockedCalled = false;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function hasActiveLock(): bool
            {
                return true;
            }

            public function lock(array $options = []): void
            {
                $this->lockedCalled = true;
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        $listener->handle($event);

        // Because hasActiveLock returned true, lock() should not have been called.
        expect($model->lockedCalled)->toBeFalse();
    });

    it('handles resolver throwing by returning null from resolveModel', function () {
        $identifier = 'resolver-throws@example.test';

        // Mock service to throw when getLoginModel is called
        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andThrow(new \RuntimeException('resolver failed'));
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        // Should not throw; resolveModel swallows exceptions and returns null
        try {
            $listener->handle($event);
            $reached = true;
        } catch (\Throwable $e) {
            $reached = false;
        }

        expect($reached)->toBeTrue();
    });
});

describe('LockController (unit)', function () {
    beforeEach(function () {
        // Ensure named route 'login' exists so redirect()->route('login') works
        Route::get('/login', fn () => 'login')->name('login');
    });

    afterEach(function () {
        \Mockery::close();
    });

    it('locks the model, sends notification and redirects when model exists', function () {
        $identifier = 'lockme@example.test';

        // Create a simple model instance
        $model = new class extends Beliven\Lockout\Tests\Support\LockableModelStub
        {
            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];
        };

        // Mock Lockout service to resolve model and accept lockModel and attemptSendLockoutNotification
        $mockService = Mockery::mock(\Beliven\Lockout\Lockout::class);
        $mockService->shouldReceive('getLoginModel')->once()->with($identifier)->andReturn($model);
        $mockService->shouldReceive('lockModel')->once()->with($model)->andReturnNull();
        $mockService->shouldReceive('attemptSendLockoutNotification')->once()->with($identifier, \Mockery::any())->andReturnNull();
        app()->instance(\Beliven\Lockout\Lockout::class, $mockService);

        $request = \Illuminate\Http\Request::create('/lockout/lock', 'GET', ['identifier' => $identifier]);

        /** @var LockController $controller */
        $controller = app(LockController::class);
        $response = $controller->__invoke($request);

        expect(method_exists($response, 'getStatusCode'))->toBeTrue();
        expect($response->getStatusCode())->toBe(302);
    });

    it('redirects with error when model not found', function () {
        $identifier = 'missing@example.test';

        $mockService = Mockery::mock(\Beliven\Lockout\Lockout::class);
        $mockService->shouldReceive('getLoginModel')->once()->with($identifier)->andReturnNull();
        app()->instance(\Beliven\Lockout\Lockout::class, $mockService);

        $request = \Illuminate\Http\Request::create('/lockout/lock', 'GET', ['identifier' => $identifier]);

        /** @var LockController $controller */
        $controller = app(LockController::class);
        $response = $controller->__invoke($request);

        expect($response->getStatusCode())->toBe(302);
    });

    it('handles exceptions from lock flow and redirects with error', function () {
        $identifier = 'errorcase@example.test';

        $mockService = Mockery::mock(\Beliven\Lockout\Lockout::class);
        $mockService->shouldReceive('getLoginModel')->once()->with($identifier)->andReturn(new class extends Beliven\Lockout\Tests\Support\LockableModelStub {});
        // Make lockModel throw to exercise controller catch branch
        $mockService->shouldReceive('lockModel')->once()->andThrow(new \RuntimeException('failed'));
        app()->instance(\Beliven\Lockout\Lockout::class, $mockService);

        $request = \Illuminate\Http\Request::create('/lockout/lock', 'GET', ['identifier' => $identifier]);

        /** @var LockController $controller */
        $controller = app(LockController::class);
        $response = $controller->__invoke($request);

        expect($response->getStatusCode())->toBe(302);
    });
});

describe('LockoutLog model', function () {
    it('exposes a morph relation named model that returns a MorphTo', function () {
        $log = new LockoutLog;

        $relation = $log->model();

        expect($relation)->toBeInstanceOf(MorphTo::class);
    });
});
