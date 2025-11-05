<?php

use Beliven\Lockout\Contracts\LockableModel;
use Beliven\Lockout\Traits\HasLockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Mockery;

/*
 * Tests for HasLockout::logoutOnLockout behaviour.
 *
 * These unit tests assert that the default implementation:
 *   - calls the correct Auth guard and invokes logout()
 *   - invalidates the session and regenerates the CSRF token
 *
 * We avoid touching the database by creating a minimal in-memory model
 * that uses the HasLockout trait and implements the LockableModel contract.
 */

describe('HasLockout::logoutOnLockout', function () {
    afterEach(function () {
        // Ensure Mockery expectations are checked and cleaned up.
        if (function_exists('Mockery::close')) {
            Mockery::close();
        }
    });

    it('logs out using the provided guard and invalidates session', function () {
        // Arrange: prepare a mock guard that expects logout()
        $mockGuard = Mockery::mock();
        $mockGuard->shouldReceive('logout')->once();

        // Expect Auth::guard('web') to be called and return our mock guard.
        Auth::shouldReceive('guard')
            ->once()
            ->with('web')
            ->andReturn($mockGuard);

        // Expect session invalidation and token regeneration
        Session::shouldReceive('invalidate')->once();
        Session::shouldReceive('regenerateToken')->once();

        // Minimal model implementing the LockableModel contract and using the trait
        $user = new class extends Model implements LockableModel
        {
            use HasLockout;

            // Implement abstract contract methods minimally for this test.
            public function lockoutLogs()
            {
                throw new \BadMethodCallException;
            }

            public function lockouts()
            {
                throw new \BadMethodCallException;
            }

            public function activeLock()
            {
                return null;
            }

            public function hasActiveLock(): bool
            {
                return false;
            }

            public function isLockedOut(): bool
            {
                return false;
            }

            public function lock(array $options = [])
            {
                throw new \BadMethodCallException;
            }

            public function unlock(array $options = [])
            {
                return null;
            }
        };

        // Act: call the trait method with the 'web' guard
        $result = $user->logoutOnLockout('web');

        // Assert: returns true and expectations on facades are verified by Mockery
        expect($result)->toBeTrue();
    });

    it('calls default guard when null is passed and invalidates session', function () {
        // Arrange: mock the guard that will be returned for null (default)
        $mockGuard = Mockery::mock();
        $mockGuard->shouldReceive('logout')->once();

        // Some Laravel versions may have Auth::guard(null) called; assert that.
        Auth::shouldReceive('guard')
            ->once()
            ->with(null)
            ->andReturn($mockGuard);

        Session::shouldReceive('invalidate')->once();
        Session::shouldReceive('regenerateToken')->once();

        $user = new class extends Model implements LockableModel
        {
            use HasLockout;

            public function lockoutLogs()
            {
                throw new \BadMethodCallException;
            }

            public function lockouts()
            {
                throw new \BadMethodCallException;
            }

            public function activeLock()
            {
                return null;
            }

            public function hasActiveLock(): bool
            {
                return false;
            }

            public function isLockedOut(): bool
            {
                return false;
            }

            public function lock(array $options = [])
            {
                throw new \BadMethodCallException;
            }

            public function unlock(array $options = [])
            {
                return null;
            }
        };

        // Act
        $result = $user->logoutOnLockout(null);

        // Assert
        expect($result)->toBeTrue();
    });
});
