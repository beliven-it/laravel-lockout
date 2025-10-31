<?php

use Beliven\Lockout\Facades\Lockout;
use Beliven\Lockout\Http\Controllers\UnlockController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

describe('UnlockController (unit)', function () {
    beforeEach(function () {
        // Ensure named route 'login' exists so redirect()->route('login') works
        Route::get('/login', fn () => 'login')->name('login');
    });

    it('calls unlock on the model when available and redirects to login', function () {
        $identifier = 'unit-locked@example.test';

        // Create a simple Eloquent model that exposes unlock()
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            public $blocked_at;

            public $unlocked = false;

            public $saved = false;

            public $timestamps = false;

            public function unlock()
            {
                $this->unlocked = true;
            }

            public function save(array $options = [])
            {
                // Mark saved and return true to mimic Eloquent save behavior
                $this->saved = true;

                return true;
            }
        };

        // Expect the Lockout facade to return our model for the identifier
        Lockout::shouldReceive('getLoginModel')->once()->with($identifier)->andReturn($model);

        $request = Request::create('/lockout/unlock', 'GET', ['identifier' => $identifier]);

        /** @var UnlockController $controller */
        $controller = app(UnlockController::class);
        $response = $controller->__invoke($request);

        // Controller should redirect to the named login route
        expect(method_exists($response, 'getStatusCode'))->toBeTrue();
        expect($response->getStatusCode())->toBe(302);

        // Model's unlockAccount should have been called
        expect($model->unlocked)->toBeTrue();
    });

    it('falls back to clearing blocked_at and saving the model when no unlock method exists', function () {
        $identifier = 'unit-fallback@example.test';

        // Model without unlock/unlockAccount methods; controller should clear blocked_at and call save()
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            public $blocked_at;

            public $saved = false;

            public $timestamps = false;

            public function save(array $options = [])
            {
                // Mark saved and return true to mimic Eloquent save behavior
                $this->saved = true;

                return true;
            }
        };

        // Set a blocked_at initially to ensure controller clears it
        $model->blocked_at = now();

        Lockout::shouldReceive('getLoginModel')->once()->with($identifier)->andReturn($model);

        $request = Request::create('/lockout/unlock', 'GET', ['identifier' => $identifier]);

        /** @var UnlockController $controller */
        $controller = app(UnlockController::class);
        $response = $controller->__invoke($request);

        expect($response->getStatusCode())->toBe(302);

        // The controller should have cleared blocked_at and persisted via save()
        expect($model->blocked_at)->toBeNull();
        expect($model->saved)->toBeTrue();
    });

    it('redirects to login with error when model is not found', function () {
        $identifier = 'nonexistent@example.test';

        // Lockout returns null when no model found
        Lockout::shouldReceive('getLoginModel')->once()->with($identifier)->andReturn(null);

        $request = Request::create('/lockout/unlock', 'GET', ['identifier' => $identifier]);

        /** @var UnlockController $controller */
        $controller = app(UnlockController::class);
        $response = $controller->__invoke($request);

        // Should redirect to login (withErrors in controller) — status 302
        expect($response->getStatusCode())->toBe(302);
    });
});
