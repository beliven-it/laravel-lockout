<?php

use Beliven\Lockout\Facades\Lockout;
use Beliven\Lockout\Http\Controllers\UnlockController;
use Beliven\Lockout\Http\Middleware\EnsureUserIsNotLocked;
use Beliven\Lockout\Lockout as LockoutService;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

describe('UnlockController and EnsureUserIsNotLocked middleware', function () {
    beforeEach(function () {
        // Test config: use array cache and small threshold
        config()->set('lockout.cache_store', 'array');
        config()->set('lockout.max_attempts', 2);
        config()->set('lockout.decay_minutes', 10);

        // Ensure auth provider points to the test fixture User
        config()->set('auth.providers.users.model', \Beliven\Lockout\Tests\Fixtures\User::class);

        // Create tables used by the package
        Schema::dropIfExists('model_lockouts');
        Schema::dropIfExists('lockout_logs');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('model_lockouts', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
        });

        Schema::create('lockout_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('model');
            $table->string('identifier')->index();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('attempted_at')->nullable();
        });

        // Ensure array cache is clean
        $cacheStore = Cache::store('array');
        if (method_exists($cacheStore, 'flush')) {
            $cacheStore->flush();
        }

        // Provide a named login route used by the controller redirect
        Route::get('/login', fn () => 'login')->name('login');

        // Register the signed unlock route used by the package so tests exercise
        // the signed middleware/route validation when hitting the URL.
        Route::middleware(['signed'])->get('/lockout/unlock', UnlockController::class)->name('lockout.unlock');
    });

    afterEach(function () {
        // Tear down DB and cache
        Schema::dropIfExists('lockout_logs');
        Schema::dropIfExists('users');

        $cacheStore = Cache::store('array');
        if (method_exists($cacheStore, 'flush')) {
            $cacheStore->flush();
        }

        // Clear any routes we've registered
        // (Orchestra/Testbench will normally handle route cleanup between tests,
        // but keeping this here avoids surprises in some environments.)
    });

    it('UnlockController clears active model lock and redirects to login', function () {
        // Seed a locked user
        $user = User::query()->create([
            'email'    => 'locked@example.test',
            'password' => Hash::make('secret'),
        ]);

        // Create an active lock record referencing the created user
        DB::table('model_lockouts')->insert([
            'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
            'model_id'   => $user->id,
            'locked_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/lockout/unlock', 'GET', [
            'identifier' => $user->email,
        ]);

        /** @var UnlockController $controller */
        $controller = app(UnlockController::class);

        $response = $controller->__invoke($request);

        // Controller should redirect (302) to named route 'login'
        expect($response)->not->toBeNull();
        expect(method_exists($response, 'getStatusCode'))->toBeTrue();
        expect($response->getStatusCode())->toBe(302);

        // Ensure the user's active lock has been cleared
        $user->refresh();
        $active = DB::table('model_lockouts')
            ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
            ->where('model_id', $user->id)
            ->whereNull('unlocked_at')
            ->first();
        expect($active)->toBeNull();
    });

    it('signed unlock route unblocks user and redirects to login', function () {
        // Seed a locked user
        $user = User::query()->create([
            'email'    => 'signed-locked@example.test',
            'password' => Hash::make('secret'),
        ]);

        // Create active lock record
        DB::table('model_lockouts')->insert([
            'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
            'model_id'   => $user->id,
            'locked_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Generate a temporary signed URL for the named route
        $signedUrl = URL::temporarySignedRoute('lockout.unlock', now()->addDay(), [
            'identifier' => $user->email,
        ]);

        // Perform a GET request against the signed URL so the 'signed' middleware runs
        $response = $this->get($signedUrl);

        // Should redirect (302) to named route 'login' as controller does
        $response->assertStatus(302);
        $response->assertRedirect(route('login'));

        // Ensure the user's active lock has been cleared
        $user->refresh();
        $active = DB::table('model_lockouts')
            ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
            ->where('model_id', $user->id)
            ->whereNull('unlocked_at')
            ->first();
        expect($active)->toBeNull();
    });

    it('uses configured unlock_redirect_route for redirects', function () {
        // Override config to use a custom named route
        config()->set('lockout.unlock_redirect_route', 'custom.login');

        // Provide the custom named route used by the controller redirect
        Route::get('/custom-login', fn () => 'custom-login')->name('custom.login');

        // Seed a locked user
        $user = User::query()->create([
            'email'    => 'custom-redirect@example.test',
            'password' => Hash::make('secret'),
        ]);

        // Create active lock record
        DB::table('model_lockouts')->insert([
            'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
            'model_id'   => $user->id,
            'locked_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Generate a temporary signed URL for the named route
        $signedUrl = URL::temporarySignedRoute('lockout.unlock', now()->addDay(), [
            'identifier' => $user->email,
        ]);

        // Perform a GET request against the signed URL so the 'signed' middleware runs
        $response = $this->get($signedUrl);

        // Should redirect (302) to the configured route name
        $response->assertStatus(302);
        $response->assertRedirect(route('custom.login'));

        // Ensure the user's active lock has been cleared
        $user->refresh();
        $active = DB::table('model_lockouts')
            ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
            ->where('model_id', $user->id)
            ->whereNull('unlocked_at')
            ->first();
        expect($active)->toBeNull();
    });

    it('rejects unlock request with invalid signature', function () {
        // Seed a locked user
        $user = User::query()->create([
            'email'    => 'tampered-locked@example.test',
            'password' => Hash::make('secret'),
        ]);

        // Create active lock record
        DB::table('model_lockouts')->insert([
            'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
            'model_id'   => $user->id,
            'locked_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Generate a valid signed URL then tamper it to invalidate the signature
        $signedUrl = URL::temporarySignedRoute('lockout.unlock', now()->addDay(), [
            'identifier' => $user->email,
        ]);

        $tamperedUrl = $signedUrl . '&tamper=1';

        // Perform GET against the tampered URL; the ValidateSignature middleware should fail
        $response = $this->get($tamperedUrl);

        // Invalid signature should result in 403 Forbidden from the signed middleware
        $response->assertStatus(403);

        // Ensure the user's active lock is still present (controller was not invoked)
        $user->refresh();
        $active = DB::table('model_lockouts')
            ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
            ->where('model_id', $user->id)
            ->whereNull('unlocked_at')
            ->first();
        expect($active)->not->toBeNull();
    });

    describe('EnsureUserIsNotLocked middleware', function () {
        it('blocks requests when model has an active lock record', function () {
            $email = 'blocked-model@example.test';
            // create blocked user
            $user = User::query()->create([
                'email'    => $email,
                'password' => Hash::make('secret'),
            ]);

            // create active lock record
            DB::table('model_lockouts')->insert([
                'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
                'model_id'   => $user->id,
                'locked_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = Request::create('/login', 'POST', [
                Lockout::getLoginField() => $email,
            ]);

            $middleware = new EnsureUserIsNotLocked;

            $next = function ($req) {
                return response('ok', 200);
            };

            $response = $middleware->handle($request, $next);

            expect($response)->not->toBeNull();
            expect($response->getStatusCode())->toBe(429);
        });

        it('blocks requests when attempts in cache exceed threshold even if model is not marked', function () {
            $email = 'blocked-cache@example.test';
            // create user without any persistent lock
            User::query()->create([
                'email'    => $email,
                'password' => Hash::make('secret'),
            ]);

            /** @var LockoutService $service */
            $service = app(LockoutService::class);

            // Increment attempts up to the configured threshold (2)
            $service->incrementAttempts($email);
            $service->incrementAttempts($email);

            // Ensure the service now considers the identifier blocked
            expect($service->hasTooManyAttempts($email))->toBeTrue();

            $request = Request::create('/login', 'POST', [
                Lockout::getLoginField() => $email,
            ]);

            $middleware = new EnsureUserIsNotLocked;

            $next = function ($req) {
                return response('ok', 200);
            };

            $response = $middleware->handle($request, $next);

            expect($response)->not->toBeNull();
            expect($response->getStatusCode())->toBe(429);
        });

        it('allows requests when identifier is not locked', function () {
            $email = 'clean@example.test';
            User::query()->create([
                'email'    => $email,
                'password' => Hash::make('secret'),
            ]);

            $request = Request::create('/login', 'POST', [
                Lockout::getLoginField() => $email,
            ]);

            $middleware = new EnsureUserIsNotLocked;

            $next = function ($req) {
                return response('ok', 200);
            };

            $response = $middleware->handle($request, $next);

            // Should proceed to the next middleware/controller
            expect($response)->not->toBeNull();
            expect($response->getStatusCode())->toBe(200);
            expect((string) $response->getContent())->toBe('ok');
        });
    });
});
