<?php

use Beliven\Lockout\Facades\Lockout;
use Beliven\Lockout\Listeners\RecordFailedLoginAttempt;
use Illuminate\Auth\Events\Failed;

describe('RecordFailedLoginAttempt listener', function () {
    it('calls Lockout::attemptLockout when credentials contain the configured identifier', function () {
        $identifier = 'user@example.test';
        // The listener will ask the facade which login field to use
        Lockout::shouldReceive('getLoginField')->once()->andReturn('email');

        // Expect attemptLockout to be called once with the identifier and an object containing ip/user_agent
        Lockout::shouldReceive('attemptLockout')
            ->once()
            ->withArgs(function ($id, $data) use ($identifier) {
                if ($id !== $identifier) {
                    return false;
                }

                // $data should be an object and contain ip and user_agent keys (may be null in tests)
                if (!is_object($data)) {
                    return false;
                }

                // The listener builds $data from request()->ip() / userAgent(); allow nulls but keys must exist
                return property_exists($data, 'ip') && property_exists($data, 'user_agent');
            })
            ->andReturnFalse();

        $listener = new RecordFailedLoginAttempt;

        // Simulate a failed authentication event with credentials that include the identifier
        $event = new Failed('web', null, ['email' => $identifier]);

        // Call the listener - assertions are handled by the facade expectation above
        $listener->handle($event);
    });

    it('does not call attemptLockout when the identifier is not present in credentials', function () {
        // Regardless of the configured login field, credentials do not contain it
        Lockout::shouldReceive('getLoginField')->once()->andReturn('email');

        // Ensure attemptLockout is never called in this scenario
        Lockout::shouldReceive('attemptLockout')->never();

        $listener = new RecordFailedLoginAttempt;

        $event = new Failed('web', null, []); // empty credentials

        // Should not throw and should not call attemptLockout
        $listener->handle($event);

        // If we reach here, the expectation on the facade mock satisfied (no calls)
        expect(true)->toBeTrue();
    });
});
