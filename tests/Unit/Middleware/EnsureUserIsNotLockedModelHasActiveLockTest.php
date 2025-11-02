<?php

use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Http\Request;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

it('executes the closure branch inside modelHasActiveLock and treats model as not locked when exists() is false', function () {
    $identifier = 'closure-branch@example.test';

    // Model with a lockouts() relation that properly accepts a closure in where(...)
    $model = new class extends \Beliven\Lockout\Tests\Support\LockableModelStub
    {
        public $timestamps = false;

        public function lockouts()
        {
            return new class
            {
                // First whereNull('unlocked_at') call
                public function whereNull($col)
                {
                    return $this;
                }

                /**
                 * The middleware calls ->where(function ($q) { ... })
                 * so this method must accept a Closure as first param and invoke it.
                 */
                public function where($col, $op = null, $val = null)
                {
                    if (is_callable($col)) {
                        // Invoke the closure with an object that supports whereNull and orWhere
                        $q = new class
                        {
                            public function whereNull($col)
                            {
                                return $this;
                            }

                            public function orWhere($a = null, $b = null, $c = null)
                            {
                                return $this;
                            }
                        };

                        $col($q);

                        return $this;
                    }

                    return $this;
                }

                public function exists()
                {
                    // Simulate no active locks found
                    return false;
                }

                public function orWhere($a = null, $b = null, $c = null)
                {
                    return $this;
                }
            };
        }
    };

    // Mock Lockout service behavior
    $mock = \Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->andReturn('email');
    $mock->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    // Ensure cache-based fallback does not block the request
    $mock->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mock);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    // Because exists() returned false in the closure branch, middleware should allow the request
    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});

it('returns false (allows request) when resolved model has neither activeLock nor lockouts methods', function () {
    $identifier = 'no-methods-branch@example.test';

    // Simple model that exposes no activeLock() or lockouts() methods
    $model = new class extends \Beliven\Lockout\Tests\Support\LockableModelStub
    {
        public $timestamps = false;
    };

    $mock = \Mockery::mock(LockoutService::class);
    $mock->shouldReceive('getLoginField')->andReturn('email');
    $mock->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    $mock->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mock);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    // Because modelHasActiveLock falls through to final return false, middleware should allow the request
    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});
