<?php

use Beliven\Lockout\Lockout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('Lockout service', function () {
    beforeEach(function () {
        // Use array cache to avoid external dependencies and have deterministic state
        config()->set('lockout.cache_store', 'array');
        Cache::store('array')->flush();

        // Default threshold used in most tests; some tests override this locally.
        config()->set('lockout.max_attempts', 3);
        config()->set('lockout.decay_minutes', 10);
        // Disable notification behavior to avoid needing a users table in unit tests
        config()->set('lockout.unlock_via_notification', false);

        // Ensure lockout_logs table exists for createLog() calls
        Schema::dropIfExists('lockout_logs');
        Schema::create('lockout_logs', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->index();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('attempted_at')->nullable();
        });
    });

    afterEach(function () {
        Cache::store('array')->flush();
        Schema::dropIfExists('lockout_logs');
    });

    describe('getAttempts', function () {
        it('returns zero when there are no attempts stored for an identifier', function () {
            $service = app(Lockout::class);

            $identifier = 'no-attempts@example.com';
            expect($service->getAttempts($identifier))->toBe(0);
        });
    });

    describe('incrementAttempts', function () {
        it('increments the stored attempts counter and getAttempts reflects the changes', function () {
            $service = app(Lockout::class);

            $identifier = 'increment@example.com';

            expect($service->getAttempts($identifier))->toBe(0);

            $service->incrementAttempts($identifier);
            expect($service->getAttempts($identifier))->toBe(1);

            $service->incrementAttempts($identifier);
            expect($service->getAttempts($identifier))->toBe(2);
        });
    });

    describe('hasTooManyAttempts', function () {
        it('returns false before threshold and true after the threshold is reached', function () {
            // Use a small threshold for this specific test to make expectations clear
            config()->set('lockout.max_attempts', 2);
            $service = app(Lockout::class);

            $identifier = 'threshold@example.com';

            // Initially not blocked
            expect($service->hasTooManyAttempts($identifier))->toBeFalse();

            // One increment -> still below threshold
            $service->incrementAttempts($identifier);
            expect($service->hasTooManyAttempts($identifier))->toBeFalse();

            // Second increment -> reaches threshold (configured 2)
            $service->incrementAttempts($identifier);
            expect($service->hasTooManyAttempts($identifier))->toBeTrue();
        });
    });

    describe('attemptLockout', function () {
        it('increments attempts, creates logs and returns true when threshold is reached', function () {
            // Ensure the test uses a known threshold
            config()->set('lockout.max_attempts', 3);
            $service = app(Lockout::class);

            $identifier = 'lockme@example.com';
            $meta = (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit'];

            // First attempt: should not be blocked yet
            $result1 = $service->attemptLockout($identifier, $meta);
            expect($result1)->toBeFalse();
            expect($service->getAttempts($identifier))->toBe(1);

            // Second attempt
            $result2 = $service->attemptLockout($identifier, $meta);
            expect($result2)->toBeFalse();
            expect($service->getAttempts($identifier))->toBe(2);

            // Third attempt -> reaches threshold and should return true
            $result3 = $service->attemptLockout($identifier, $meta);
            expect($result3)->toBeTrue();
            expect($service->hasTooManyAttempts($identifier))->toBeTrue();

            // Ensure logs were created for each attempt
            $logsCount = DB::table('lockout_logs')->where('identifier', $identifier)->count();
            expect($logsCount)->toBe(3);
        });

        it('returns true immediately if identifier was already blocked', function () {
            config()->set('lockout.max_attempts', 2);
            $service = app(Lockout::class);

            $identifier = 'alreadyblocked@example.com';
            $meta = (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit'];

            // Simulate reaching the threshold
            $service->incrementAttempts($identifier);
            $service->incrementAttempts($identifier);

            // The identifier is now considered blocked
            expect($service->hasTooManyAttempts($identifier))->toBeTrue();

            // attemptLockout should return true without creating extra unexpected behavior
            $result = $service->attemptLockout($identifier, $meta);
            expect($result)->toBeTrue();

            // And there should be no additional side-effect beyond what attemptLockout intentionally does.
            // At least ensure attempts count remains >= threshold.
            expect($service->getAttempts($identifier))->toBeGreaterThanOrEqual(2);
        });
    });
});
