<?php

use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout;
use Beliven\Lockout\Models\ModelLockout;
use Beliven\Lockout\Traits\HasLockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * Ensure the MarkModelAsLocked listener returns early when the resolved model
 * already reports an active lock (hasActiveLock() === true).
 */
it('MarkModelAsLocked early-returns when model hasActiveLock true', function () {
    $identifier = 'already-locked@example.test';

    // Model that exposes both hasActiveLock() and lock() but reports already locked.
    $model = new class extends Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public $locked = false;

        protected $table = 'users';

        public function hasActiveLock(): bool
        {
            return true;
        }

        public function lock($options = null): void
        {
            // If this were called it would mark locked true; we assert it is NOT called.
            $this->locked = true;
        }
    };

    // Mock the Lockout service to resolve our model for the identifier.
    $mockService = Mockery::mock(Lockout::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

    app()->instance(Lockout::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new \Beliven\Lockout\Events\EntityLocked($identifier, (object) []);

    // Execute: since hasActiveLock() returns true, lock() must NOT be invoked.
    $listener->handle($event);

    expect($model->locked)->toBeFalse();
});

/**
 * Ensure Lockout::unlockModel continues and returns the lock when clearAttempts()
 * throws an exception (it should be swallowed).
 */
it('Lockout::unlockModel returns lock even when clearAttempts throws', function () {
    // Bind a Lockout subclass that throws from clearAttempts to simulate failure.
    app()->bind(Lockout::class, function () {
        return new class extends Lockout
        {
            public function clearAttempts(string $id): void
            {
                throw new \RuntimeException('simulated-clear-failure');
            }
        };
    });

    /** @var Lockout $service */
    $service = app(Lockout::class);

    // Prepare a model that has the configured login field value
    $model = new class extends Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public $email = 'victim@example.test';
    };

    // Prepare a lock-like ModelLockout instance whose save() returns true
    $lock = new class extends ModelLockout
    {
        public $unlocked_at;

        public $meta;

        public $reason;

        // Ensure signature matches Eloquent's save()
        public function save(array $options = [])
        {
            return true;
        }
    };

    // Model should return the active lock when activeLock() is called
    $modelWithActive = new class($lock) extends Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public $email = 'victim@example.test';

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

    // Call unlockModel; even though clearAttempts throws, unlockModel should swallow and return the lock
    $result = $service->unlockModel($modelWithActive, []);

    expect($result)->toBe($lock);
});

/**
 * Ensure HasLockout trait's relation helpers return MorphMany instances.
 */
it('HasLockout relations return MorphMany', function () {
    // Anonymous model using the trait
    $model = new class extends Illuminate\Database\Eloquent\Model
    {
        use HasLockout;

        public $timestamps = false;

        protected $table = 'users';
    };

    $lockoutsRelation = $model->lockouts();
    $logsRelation = $model->lockoutLogs();

    expect($lockoutsRelation)->toBeInstanceOf(MorphMany::class);
    expect($logsRelation)->toBeInstanceOf(MorphMany::class);
});
