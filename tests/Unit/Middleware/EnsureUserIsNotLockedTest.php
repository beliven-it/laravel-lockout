<?php

use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

afterEach(function () {
    \Mockery::close();
});

it('passes the request through when the identifier is not present', function () {
    $request = Request::create('/login', 'POST', []); // no identifier in payload

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});

it('treats model->activeLock throwing as not locked and allows the request', function () {
    $identifier = 'throwing-active@example.test';

    // Create a dummy model whose activeLock() throws an exception
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        // Keep Eloquent silent in tests (no timestamps required)
        public $timestamps = false;

        public function activeLock()
        {
            throw new \RuntimeException('simulated failure');
        }
    };

    // Mock the Lockout service to return our model and a login field
    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginField')->andReturn('email');
    $mockService->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    // Ensure cache-based check is false so middleware would pass if model considered not locked
    $mockService->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mockService);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    // Because activeLock() threw, middleware should treat model as not locked and proceed.
    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
});

it('treats relation query exceptions as not locked and allows the request', function () {
    $identifier = 'relation-throws@example.test';

    // Model without activeLock(), but with a lockouts() relation that throws during exists/query.
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        // Prevent Eloquent from requiring timestamps or DB for this dummy
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
                    throw new \RuntimeException('DB simulated failure');
                }

                // allow the chained where(...) -> where(function...) pattern by returning self for orWhere etc.
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
    $mockService->shouldReceive('hasTooManyAttempts')->with($identifier)->andReturn(false);

    app()->instance(LockoutService::class, $mockService);

    $request = Request::create('/login', 'POST', ['email' => $identifier]);

    $middleware = new EnsureUserIsNotLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    // Because the relation query threw, middleware treats the model as not locked and allows request.
    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
});

it('blocks the request when model.activeLock returns a lock (lockedResponse)', function () {
    $identifier = 'blocked-by-model@example.test';

    // Model whose activeLock() returns a non-null value (simulates an active lock)
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        // Keep Eloquent silent in tests (no timestamps required)
        public $timestamps = false;

        public function activeLock()
        {
            return (object) ['id' => 1];
        }
    };

    $mockService = Mockery::mock(LockoutService::class);
    $mockService->shouldReceive('getLoginField')->andReturn('email');
    $mockService->shouldReceive('getLoginModel')->with($identifier)->andReturn($model);
    // Make sure cache check would not also block (we rely on model path here)
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
    // Response body should contain the translated message key (we don't assert exact text to avoid locale differences)
    $json = json_decode((string) $response->getContent(), true);
    expect(array_key_exists('message', $json))->toBeTrue();
});
