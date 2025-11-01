<?php

use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Beliven\Lockout\Traits\HasLockout;
use Illuminate\Database\Eloquent\Model as EloquentModel;

afterEach(function () {
    // Ensure mockery cleaned between tests
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

/**
 * Ensure the listener gracefully returns when the resolved model does not
 * expose the expected helper methods (hasActiveLock / lock).
 */
it('mark listener is a no-op when resolved model lacks lock helper methods', function () {
    $identifier = 'no-methods@example.test';

    // Create a simple Eloquent-like model that intentionally lacks hasActiveLock() and lock()
    $model = new class extends EloquentModel
    {
        // Prevent Eloquent from requiring DB/timestamps in this test
        public $timestamps = false;

        protected $table = 'users';
    };

    // Mock the Lockout service so the listener resolves our dummy model
    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

    app()->instance(LockoutService::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new \Beliven\Lockout\Events\EntityLocked($identifier, (object) []);

    // Should complete without throwing and without attempting to call unavailable methods
    $listener->handle($event);

    // Reached end without exception
    expect(true)->toBeTrue();
});

/**
 * Ensure the listener is a no-op when the model exposes only partial helpers:
 * e.g. hasActiveLock exists but lock() is missing (or viceversa).
 */
it('mark listener is a no-op when model is missing lock() even if hasActiveLock exists', function () {
    $identifier = 'partial-methods@example.test';

    // Model with hasActiveLock() but no lock() method
    $model = new class extends EloquentModel
    {
        public $timestamps = false;

        protected $table = 'users';

        public function hasActiveLock(): bool
        {
            return false;
        }
    };

    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginModel')->with($identifier)->once()->andReturn($model);

    app()->instance(LockoutService::class, $mockService);

    $listener = new MarkModelAsLocked;
    $event = new \Beliven\Lockout\Events\EntityLocked($identifier, (object) []);

    // Should not throw
    $listener->handle($event);

    expect(true)->toBeTrue();
});

/**
 * Test HasLockout::isLockedOut guard when the model does not expose the configured
 * login field. In that case the trait should return false without calling Lockout.
 */
it('hasLockout isLockedOut returns false when configured login field is missing on model', function () {
    // Anonymous model that uses the HasLockout trait but overrides hasActiveLock
    // to avoid DB interaction. It intentionally does not expose the configured login field.
    $model = new class extends EloquentModel
    {
        use HasLockout;

        public $timestamps = false;

        protected $table = 'users';

        // Ensure DB queries are not executed in this unit test
        public function hasActiveLock(): bool
        {
            return false;
        }
    };

    // Ensure the configured login field is something not present on the model (default is 'email')
    config()->set('lockout.login_field', 'email');

    // The model instance does not have the 'email' attribute set, so isLockedOut should short-circuit false
    expect($model->isLockedOut())->toBeFalse();
});

/**
 * Test Lockout service direct accessors: getLoginField, getLoginModelClass and getLoginModel fallback.
 */
it('lockout service returns configured login field and resolves login model class', function () {
    // Configure a fake model class for resolution
    config()->set('lockout.login_field', 'username');
    config()->set('auth.providers.users.model', \FakeResolverModel::class);

    // Define the fake resolver class in-place (PHP allows defining here)
    if (!class_exists('FakeResolverModel')) {
        eval(<<<'PHP'
        class FakeResolverModel {
            public static function where($field, $value) {
                return new class {
                    public function first() {
                        // Simulate not finding a model
                        return null;
                    }
                };
            }
        }
        PHP
        );
    }

    $service = app(LockoutService::class);

    // getLoginField should reflect the config we set
    expect($service->getLoginField())->toBe('username');

    // getLoginModelClass should return the configured model class
    expect($service->getLoginModelClass())->toBe(\FakeResolverModel::class);

    // getLoginModel should call the configured class and return null (as our stub does)
    expect($service->getLoginModel('doesnotexist'))->toBeNull();
});
