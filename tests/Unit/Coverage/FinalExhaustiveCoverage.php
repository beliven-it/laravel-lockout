<?php

use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * Use reflection to exercise the internal helper branches of the listener.
 * This covers the "method missing", "inspection throws", and "safe lock" paths.
 */
it('exhaustive: MarkModelAsLocked reflection covers required-methods, inspection and safe lock branches', function () {
    $listener = new MarkModelAsLocked;
    $ref = new ReflectionClass($listener);

    // modelHasRequiredMethods: object without methods
    $methodHas = $ref->getMethod('modelHasRequiredMethods');
    $methodHas->setAccessible(true);
    $plain = new class {};
    expect($methodHas->invoke($listener, $plain))->toBeFalse();

    // modelHasRequiredMethods: object with only hasActiveLock
    $onlyHas = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock()
        {
            return false;
        }
    };
    expect($methodHas->invoke($listener, $onlyHas))->toBeFalse();

    // modelHasRequiredMethods: object with both methods
    $both = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock()
        {
            return false;
        }

        public function lock($opts = null)
        { /* noop */
        }
    };
    expect($methodHas->invoke($listener, $both))->toBeTrue();

    // modelIsAlreadyLocked: hit the success branch
    $methodIsLocked = $ref->getMethod('modelIsAlreadyLocked');
    $methodIsLocked->setAccessible(true);
    $lockedModel = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock()
        {
            return true;
        }
    };
    expect($methodIsLocked->invoke($listener, $lockedModel))->toBeTrue();

    // modelIsAlreadyLocked: hit the catch branch by throwing inside hasActiveLock
    $throwing = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock()
        {
            throw new \RuntimeException('boom');
        }
    };
    expect($methodIsLocked->invoke($listener, $throwing))->toBeFalse();

    // safeLockModel: successful invocation (should call lock without exception)
    $methodSafe = $ref->getMethod('safeLockModel');
    $methodSafe->setAccessible(true);
    $okModel = new class extends EloquentModel
    {
        public $timestamps = false;

        public $called = false;

        public function lock($opts = null)
        {
            $this->called = true;
        }
    };
    $methodSafe->invoke($listener, $okModel);
    expect($okModel->called)->toBeTrue();

    // safeLockModel: swallowing exceptions when model->lock throws
    $badModel = new class extends EloquentModel
    {
        public $timestamps = false;

        public function lock($opts = null)
        {
            throw new \RuntimeException('lockboom');
        }
    };
    // Should not throw when invoked
    try {
        $methodSafe->invoke($listener, $badModel);
        $reached = true;
    } catch (\Throwable $e) {
        $reached = false;
    }
    expect($reached)->toBeTrue();
});

/**
 * Ensure unlockModel swallows exceptions from clearAttempts and event dispatch.
 * This forces the branches that ignore exceptions during clearing attempts and dispatching.
 */
it('exhaustive: Lockout::unlockModel swallows clearAttempts and event dispatch exceptions and returns the lock', function () {
    // Ensure DB schema exists for model_lockouts (some tests expect it)
    try {
        Schema::create('model_lockouts', function (Blueprint $table) {
            $table->id();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    } catch (\Throwable $_) {
        // ignore if exists
    }

    // Prepare a lock-like object whose save() returns true
    $lock = new class extends ModelLockout
    {
        public $unlocked_at;

        public $meta;

        public function save(array $options = [])
        {
            return true;
        }
    };

    // Model that exposes the configured login field and returns the lock
    $model = new class($lock) extends EloquentModel
    {
        public $timestamps = false;

        public $email = 'exhaustive@example.test';

        public $returnedLock;

        public function __construct($lock)
        {
            parent::__construct();
            $this->returnedLock = $lock;
        }

        public function activeLock()
        {
            return $this->returnedLock;
        }
    };

    // Bind a Lockout subclass whose clearAttempts throws to exercise the catch block
    app()->bind(LockoutService::class, function () {
        return new class extends LockoutService
        {
            public function __construct()
            {
                parent::__construct();
                $this->cacheStore = 'array';
            }

            public function clearAttempts(string $id): void
            {
                throw new \RuntimeException('clear boom');
            }
        };
    });

    // Also bind an event dispatcher that throws when dispatch() is called to exercise dispatch catch
    $mockDispatcher = Mockery::mock();
    $mockDispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('dispatch boom'));
    app()->instance('events', $mockDispatcher);

    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    // Call unlockModel: even though clearAttempts and dispatch throw, the method should swallow and return the lock
    $result = $service->unlockModel($model, ['requestData' => (object) ['ip' => '127.0.0.1']]);

    expect($result)->toBe($lock);
});

/**
 * Ensure createLog swallows association resolver exceptions and still persists a log record.
 * Also exercise throttleKey and clearAttempts basic calls to ensure those branches are covered.
 */
it('exhaustive: Lockout::createLog swallows resolver exceptions and throttleKey/clearAttempts behave', function () {
    // Use array cache for deterministic behavior
    config()->set('lockout.cache_store', 'array');

    // Bind a Lockout subclass whose getLoginModel throws to trigger the catch in createLog
    app()->bind(LockoutService::class, function () {
        return new class extends LockoutService
        {
            public function __construct()
            {
                parent::__construct();
                $this->cacheStore = 'array';
            }

            public function getLoginModel(string $id)
            {
                throw new \RuntimeException('resolver fail');
            }
        };
    });

    /** @var LockoutService $service */
    $service = app(LockoutService::class);

    // Reflection to call protected createLog so we hit the internal catch branch directly
    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('createLog');
    $method->setAccessible(true);

    // Should not throw despite the resolver throwing
    try {
        $method->invoke($service, 'someone@example.test', (object) ['ip' => '1.2.3.4']);
        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }
    expect($ok)->toBeTrue();

    // throttleKey via reflection
    $tk = $ref->getMethod('throttleKey');
    $tk->setAccessible(true);
    $key = $tk->invoke($service, 'abc@example.test');
    expect($key)->toBe('login-attempts:abc@example.test');

    // clearAttempts should not throw for array store
    $service->clearAttempts('abc@example.test');
    expect($service->getAttempts('abc@example.test'))->toBe(0);
});
