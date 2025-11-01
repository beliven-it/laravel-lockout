<?php

use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Http\Request;

afterEach(function () {
    \Mockery::close();
});

it('allows the request when model has a lockouts relation and no active locks exist', function () {
    $identifier = 'no-active-locks@example.test';

    // Model with a lockouts() relation that simulates query builder and returns exists() => false
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public function lockouts()
        {
            return new class
            {
                public function whereNull($col)
                {
                    return $this;
                }

                public function where($col, $op = null, $val = null)
                {
                    return $this;
                }

                public function whereCallable($c)
                {
                    return $this;
                }

                public function exists()
                {
                    return false;
                }

                public function orWhere($a = null, $b = null, $c = null)
                {
                    return $this;
                }
            };
        }
    };

    // Mock Lockout service to resolve the model and ensure cache check does not block
    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginField')->andReturn('email');
    $mockService->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    $mockService->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mockService);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});

it('blocks the request when model has a lockouts relation that reports an active lock exists', function () {
    $identifier = 'active-locks@example.test';

    // Model with a lockouts() relation that simulates query builder and returns exists() => true
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        public function lockouts()
        {
            return new class
            {
                public function whereNull($col)
                {
                    return $this;
                }

                public function where($col, $op = null, $val = null)
                {
                    return $this;
                }

                public function whereCallable($c)
                {
                    return $this;
                }

                public function exists()
                {
                    return true;
                }

                public function orWhere($a = null, $b = null, $c = null)
                {
                    return $this;
                }
            };
        }
    };

    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginField')->andReturn('email');
    $mockService->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    // Ensure cache check would not also block (we rely on model path here)
    $mockService->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mockService);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(429);

    $json = json_decode((string) $response->getContent(), true);
    expect(array_key_exists('message', $json))->toBeTrue();
});
