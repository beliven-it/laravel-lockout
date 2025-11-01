<?php

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

describe('Lockout flow', function () {
    beforeEach(function () {
        // Use a fast in-memory cache for tests
        config()->set('lockout.cache_store', 'array');

        // Make threshold small for tests
        config()->set('lockout.max_attempts', 2);
        config()->set('lockout.decay_minutes', 10);

        // Use the existing fixture User provided in tests/Fixtures/User.php
        config()->set('auth.providers.users.model', \Beliven\Lockout\Tests\Fixtures\User::class);

        // Create tables needed for the package and tests
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

        // Clear array cache store to have deterministic state
        $cacheStore = Cache::store('array');
        if (method_exists($cacheStore, 'flush')) {
            $cacheStore->flush();
        }

        // Seed a test user (use query() to satisfy static analysis)
        \Beliven\Lockout\Tests\Fixtures\User::query()->create([
            'email'    => 'test@example.com',
            'password' => Hash::make('secret'),
        ]);
    });

    afterEach(function () {
        // Clean up tables to avoid leaking state between tests
        Schema::dropIfExists('model_lockouts');
        Schema::dropIfExists('lockout_logs');
        Schema::dropIfExists('users');
        $cacheStore = Cache::store('array');
        if (method_exists($cacheStore, 'flush')) {
            $cacheStore->flush();
        }
    });

    it('increments attempts on failed login', function () {
        $identifier = 'test@example.com';

        // Initially there are no attempts
        expect(Lockout::getAttempts($identifier))->toBe(0);

        // Simulate a failed authentication event (as Laravel would dispatch)
        event(new Failed('web', null, ['email' => $identifier]));

        // After one failed attempt, attempts should be 1
        expect(Lockout::getAttempts($identifier))->toBe(1);

        // A log entry should have been created
        $log = DB::table('lockout_logs')->where('identifier', $identifier)->first();
        expect($log)->not->toBeNull();
        expect($log->identifier)->toBe($identifier);
    });

    it('locks the account after reaching max attempts and sets locked_at', function () {
        $identifier = 'test@example.com';

        // Sanity: max attempts configured to 2 in beforeEach
        expect(config('lockout.max_attempts'))->toBe(2);

        // Trigger two failed attempts
        event(new Failed('web', null, ['email' => $identifier]));
        event(new Failed('web', null, ['email' => $identifier]));

        // Now the service should consider the identifier blocked
        expect(Lockout::hasTooManyAttempts($identifier))->toBeTrue();

        // The model should have been marked as locked (a model_lockouts record exists)
        $user = \Beliven\Lockout\Tests\Fixtures\User::query()->where('email', $identifier)->first();
        expect($user)->not->toBeNull();
        // Reload fresh from DB to ensure listeners persisted the change
        $user->refresh();

        $exists = DB::table('model_lockouts')
            ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
            ->where('model_id', $user->id)
            ->whereNull('unlocked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        expect($exists)->toBeTrue();

        // And an EntityLocked dispatch should have generated another log entry as well
        $logsCount = DB::table('lockout_logs')->where('identifier', $identifier)->count();
        expect($logsCount)->toBeGreaterThanOrEqual(2);
    });
});
