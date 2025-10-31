<?php

use Beliven\Lockout\Lockout;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

describe('HasLockout trait', function () {
    beforeEach(function () {
        // Use in-memory array cache for deterministic behavior
        config()->set('lockout.cache_store', 'array');
        config()->set('lockout.max_attempts', 3);
        Cache::store('array')->flush();

        // Ensure users table exists for the User fixture model
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
        });
    });

    afterEach(function () {
        // Tear down DB and cache
        Schema::dropIfExists('users');
        Cache::store('array')->flush();
    });

    describe('isLockedOut()', function () {
        it('returns true when the model has blocked_at set', function () {
            $user = User::create([
                'email'      => 'blocked@example.test',
                'password'   => 'secret',
                'blocked_at' => now(),
            ]);

            expect($user->isLockedOut())->toBeTrue();
        });

        it('returns false when neither blocked_at nor attempts are present', function () {
            $user = User::create([
                'email'      => 'clean@example.test',
                'password'   => 'secret',
                'blocked_at' => null,
            ]);

            expect($user->isLockedOut())->toBeFalse();
        });

        it('returns true when attempts in cache exceed threshold even if blocked_at is null', function () {
            $identifier = 'attempts@example.test';

            // create user with no blocked_at
            $user = User::create([
                'email'      => $identifier,
                'password'   => 'secret',
                'blocked_at' => null,
            ]);

            /** @var Lockout $service */
            // Ensure threshold is 2 for this sub-test to be explicit
            config()->set('lockout.max_attempts', 2);

            // Recreate the service instance after changing configuration so the constructor
            // reads the updated 'lockout.max_attempts' value.
            $service = new \Beliven\Lockout\Lockout;

            // increment attempts up to threshold
            $service->incrementAttempts($identifier);
            expect($service->getAttempts($identifier))->toBe(1);
            expect($service->hasTooManyAttempts($identifier))->toBeFalse();

            $service->incrementAttempts($identifier);
            expect($service->getAttempts($identifier))->toBe(2);
            expect($service->hasTooManyAttempts($identifier))->toBeTrue();

            // refresh the user from DB and ensure the trait reads the cache fallback
            $user->refresh();
            expect($user->isLockedOut())->toBeTrue();
        });
    });

    describe('lock() and unlock()', function () {
        it('lock() sets blocked_at and persists the change', function () {
            $user = User::create([
                'email'      => 'to-lock@example.test',
                'password'   => 'secret',
                'blocked_at' => null,
            ]);

            expect($user->blocked_at)->toBeNull();

            $user->lock();

            // reload from DB to ensure persistence
            $user->refresh();
            expect($user->blocked_at)->not->toBeNull();
        });

        it('unlock() clears blocked_at and persists the change', function () {
            $user = User::create([
                'email'      => 'to-unlock@example.test',
                'password'   => 'secret',
                'blocked_at' => now(),
            ]);

            expect($user->blocked_at)->not->toBeNull();

            $user->unlock();

            $user->refresh();
            expect($user->blocked_at)->toBeNull();
        });
    });
});
