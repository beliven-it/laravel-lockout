<?php

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Database\Eloquent\Model as EloquentModel;

describe('MarkModelAsLocked listener', function () {
    afterEach(function () {
        \Mockery::close();
    });

    it('calls lock() on the model when method exists', function () {
        $identifier = 'user-with-lock@example.test';

        // Create an Eloquent-like model that exposes a lock() method
        $model = new class extends EloquentModel
        {
            public $locked = false;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function lock(): void
            {
                $this->locked = true;
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

    it('sets blocked_at and saves when model has no lock() method', function () {
        $identifier = 'user-without-lock@example.test';

        // Model that does NOT implement lock(), but has save() overriden to avoid DB interaction
        $model = new class extends EloquentModel
        {
            public $blocked_at;

            public $saved = false;

            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) ['ip' => '127.0.0.1']);

        // Execute
        $listener->handle($event);

        // The listener should have set blocked_at and persisted via save()
        expect($model->blocked_at)->not->toBeNull();
        expect($model->saved)->toBeTrue();
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
});
