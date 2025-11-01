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

        // Create a model that records attributes passed to lockouts()->create(...)
        $model = new class extends EloquentModel
        {
            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            // property where the created attributes will be captured
            public $lastCreated = null;

            public function lockouts()
            {
                $parent = $this;

                return new class($parent)
                {
                    protected $parent;

                    public function __construct($parent)
                    {
                        $this->parent = $parent;
                    }

                    public function create(array $attrs)
                    {
                        // Capture the attributes on the parent model for assertions.
                        $this->parent->lastCreated = $attrs;

                        // Return a simple object representing the created record.
                        return (object) $attrs;
                    }
                };
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
        expect(array_key_exists('expires_at', $model->lastCreated))->toBeTrue();

        // When auto_unlock_hours > 0, expires_at must be a Carbon (timestamp) and not null
        expect($model->lastCreated['expires_at'])->not->toBeNull();
    });

    it('stores null expires_at when auto_unlock_hours is 0 (manual unlock only)', function () {
        $identifier = 'noupcase@example.test';

        // Configure auto-unlock disabled
        config()->set('lockout.auto_unlock_hours', 0);

        // Model that captures created attributes similarly to the previous test
        $model = new class extends EloquentModel
        {
            protected $table = 'users';

            public $timestamps = false;

            protected $guarded = [];

            public $lastCreated = null;

            public function lockouts()
            {
                $parent = $this;

                return new class($parent)
                {
                    protected $parent;

                    public function __construct($parent)
                    {
                        $this->parent = $parent;
                    }

                    public function create(array $attrs)
                    {
                        $this->parent->lastCreated = $attrs;

                        return (object) $attrs;
                    }
                };
            }
        };

        $mockService = Mockery::mock(LockoutService::class);
        $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);
        app()->instance(LockoutService::class, $mockService);

        $listener = new MarkModelAsLocked;
        $event = new EntityLocked($identifier, (object) []);

        $listener->handle($event);

        // Verify the created attributes exist and expires_at is explicitly null
        expect($model->lastCreated)->not->toBeNull();
        expect(array_key_exists('expires_at', $model->lastCreated))->toBeTrue();
        expect($model->lastCreated['expires_at'])->toBeNull();
    });
});
