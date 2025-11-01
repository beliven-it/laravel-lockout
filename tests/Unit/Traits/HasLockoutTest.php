<?php

use Beliven\Lockout\Lockout;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('HasLockout trait', function () {
    beforeEach(function () {
        // Use in-memory array cache for deterministic behavior
        config()->set('lockout.cache_store', 'array');
        config()->set('lockout.max_attempts', 3);
        Cache::store('array')->flush();

        // Ensure users and model_lockouts tables exist for the User fixture model
        Schema::dropIfExists('model_lockouts');
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
    });

    afterEach(function () {
        // Tear down DB and cache
        Schema::dropIfExists('model_lockouts');
        Schema::dropIfExists('users');
        Cache::store('array')->flush();
    });

    describe('isLockedOut()', function () {
        it('returns true when the model has an active lock record', function () {
            $user = User::create([
                'email'    => 'blocked@example.test',
                'password' => 'secret',
            ]);

            // create an active lock record
            DB::table('model_lockouts')->insert([
                'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
                'model_id'   => $user->id,
                'locked_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            expect($user->isLockedOut())->toBeTrue();
        });

        it('returns false when neither active lock nor attempts are present', function () {
            $user = User::create([
                'email'    => 'clean@example.test',
                'password' => 'secret',
            ]);

            expect($user->isLockedOut())->toBeFalse();
        });

        it('returns true when attempts in cache exceed threshold even if no active lock exists', function () {
            $identifier = 'attempts@example.test';

            // create user with no persistent lock
            $user = User::create([
                'email'    => $identifier,
                'password' => 'secret',
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
        it('lock() creates a model_lockouts record and is considered active', function () {
            $user = User::create([
                'email'    => 'to-lock@example.test',
                'password' => 'secret',
            ]);

            // Initially no active lock
            $existsBefore = DB::table('model_lockouts')
                ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
                ->where('model_id', $user->id)
                ->whereNull('unlocked_at')
                ->exists();
            expect($existsBefore)->toBeFalse();

            // Use trait's lock() helper
            $user->lock();

            // reload from DB to ensure persistence
            $user->refresh();

            $existsAfter = DB::table('model_lockouts')
                ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
                ->where('model_id', $user->id)
                ->whereNull('unlocked_at')
                ->exists();
            expect($existsAfter)->toBeTrue();
        });

        it('unlock() marks the active lock as unlocked and persists the change', function () {
            $user = User::create([
                'email'    => 'to-unlock@example.test',
                'password' => 'secret',
            ]);

            // create an active lock record
            DB::table('model_lockouts')->insert([
                'model_type' => \Beliven\Lockout\Tests\Fixtures\User::class,
                'model_id'   => $user->id,
                'locked_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Sanity: active lock exists
            $activeBefore = DB::table('model_lockouts')
                ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
                ->where('model_id', $user->id)
                ->whereNull('unlocked_at')
                ->first();
            expect($activeBefore)->not->toBeNull();

            $user->unlock();

            $user->refresh();

            $activeAfter = DB::table('model_lockouts')
                ->where('model_type', \Beliven\Lockout\Tests\Fixtures\User::class)
                ->where('model_id', $user->id)
                ->whereNull('unlocked_at')
                ->first();
            expect($activeAfter)->toBeNull();
        });
    });
});
