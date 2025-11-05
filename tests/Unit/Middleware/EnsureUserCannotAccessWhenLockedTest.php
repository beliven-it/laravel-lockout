<?php

use Beliven\Lockout\Http\Middleware\EnsureUserCannotAccessWhenLocked;
use Illuminate\Http\Request;

afterEach(function () {
    if (function_exists('\Mockery::close')) {
        \Mockery::close();
    }
});

it('passes the request through when there is no authenticated user', function () {
    $request = Request::create('/protected', 'GET');

    $middleware = new EnsureUserCannotAccessWhenLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});

it('passes the request through when the authenticated user is not locked', function () {
    // Create a user stub that reports not locked
    $user = new class extends \Beliven\Lockout\Tests\Support\LockableModelStub
    {
        public function isLockedOut(): bool
        {
            return false;
        }
    };

    $request = Request::create('/protected', 'GET');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $middleware = new EnsureUserCannotAccessWhenLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toBe('ok');
});

it('returns a 429 JSON response when the authenticated user is locked', function () {
    // Create a user stub that reports locked
    $user = new class extends \Beliven\Lockout\Tests\Support\LockableModelStub
    {
        public function isLockedOut(): bool
        {
            return true;
        }
    };

    $request = Request::create('/protected', 'GET');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $middleware = new EnsureUserCannotAccessWhenLocked;

    $next = function ($req) {
        return response('ok', 200);
    };

    $response = $middleware->handle($request, $next);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(429);

    $json = json_decode((string) $response->getContent(), true);
    expect(is_array($json))->toBeTrue();
    expect(array_key_exists('message', $json))->toBeTrue();
});
