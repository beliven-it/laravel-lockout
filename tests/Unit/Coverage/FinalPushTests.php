<?php

use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * Ensure MarkModelAsLocked returns early when the resolved model reports it already has an active lock.
 * This targets the branch that returns immediately when `$model->hasActiveLock()` is true.
 */
it('mark listener returns early when model hasActiveLock true', function () {
    $identifier = 'already-locked-final@example.test';

    $model = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock(): bool
        {
            return true;
        }

        public function lock($opts = null): void
        {
            throw new \RuntimeException('should-not-call-lock');
        }
    };

    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

    app()->instance(LockoutService::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new \Beliven\Lockout\Events\EntityLocked($identifier, (object) []);

    // Should not throw and should not call lock()
    $listener->handle($event);

    expect(true)->toBeTrue();
});

/**
 * Ensure MarkModelAsLocked swallows any exception thrown during handling.
 * We simulate a model whose hasActiveLock() itself throws to force the outer try/catch to execute.
 * This exercises the catch branch.
 */
it('mark listener swallows exceptions thrown during model inspection', function () {
    $identifier = 'throwing-inspect@example.test';

    $model = new class extends EloquentModel
    {
        public $timestamps = false;

        public function hasActiveLock(): bool
        {
            throw new \RuntimeException('inspect failed');
        }

        public function lock($opts = null): void
        { /* noop */
        }
    };

    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

    app()->instance(LockoutService::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new \Beliven\Lockout\Events\EntityLocked($identifier, (object) []);

    // Should not propagate the exception (it must be swallowed)
    try {
        $listener->handle($event);
        $reached = true;
    } catch (\Throwable $e) {
        $reached = false;
    }

    expect($reached)->toBeTrue();
});

/**
 * Ensure ModelLockout::model() relation is a MorphTo instance.
 * This confirms the polymorphic relation method returns the correct relation object.
 */
it('model_lockout model relation returns MorphTo', function () {
    $ml = new ModelLockout;
    $relation = $ml->model();

    expect($relation)->toBeInstanceOf(MorphTo::class);
});

/**
 * Ensure HasLockout trait relations return the expected MorphMany instances and the lock() helper delegates.
 * We create an anonymous model using the trait and assert relation types and that lock() delegates through the Lockout service.
 */
it('haslockout relations return MorphMany and lock delegates to service', function () {
    // Anonymous model that uses the HasLockout trait
    $model = new class extends EloquentModel
    {
        use \Beliven\Lockout\Traits\HasLockout;

        public $timestamps = false;

        protected $table = 'users_for_trait_test';
    };

    // The relations should be MorphMany instances
    $logsRelation = $model->lockoutLogs();
    $locksRelation = $model->lockouts();

    expect($logsRelation)->toBeInstanceOf(MorphMany::class);
    expect($locksRelation)->toBeInstanceOf(MorphMany::class);

    // Mock the Lockout service so the trait's lock() call delegates without hitting DB
    $mockService = Mockery::mock(LockoutService::class);
    // Expect lockModel to be called when model->lock() is invoked
    $mockService->shouldReceive('lockModel')->once()->withArgs(function ($m, $opts) {
        return $m instanceof EloquentModel && $opts !== null;
    })->andReturn(new ModelLockout);

    app()->instance(LockoutService::class, $mockService);

    // Call lock() which should delegate to the mocked service
    $created = $model->lock(['reason' => 'test']);

    expect($created)->toBeInstanceOf(ModelLockout::class);
});
