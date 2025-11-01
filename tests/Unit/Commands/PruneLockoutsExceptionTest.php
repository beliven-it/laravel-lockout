<?php

use Beliven\Lockout\Commands\PruneLockouts;
use Illuminate\Console\Command;

afterEach(function () {
    // Ensure any custom binding is cleared to avoid polluting other tests.
    try {
        app()->forgetInstance(PruneLockouts::class);
    } catch (\Throwable $_) {
        // ignore
    }
});

it('returns failure when pruneLockoutLogs throws an exception', function () {
    // Bind a custom command that throws during pruneLockoutLogs()
    app()->bind(PruneLockouts::class, function () {
        return new class extends PruneLockouts
        {
            protected function pruneLockoutLogs(int $days): int
            {
                throw new \RuntimeException('simulated-prune-error');
            }
        };
    });

    $this->artisan(PruneLockouts::class, ['--force' => true])
        ->assertExitCode(Command::FAILURE);
});

it('returns failure when pruneModelLockouts throws an exception', function () {
    // Bind a custom command that succeeds pruning logs but throws during pruneModelLockouts()
    app()->bind(PruneLockouts::class, function () {
        return new class extends PruneLockouts
        {
            protected function pruneLockoutLogs(int $days): int
            {
                return 0;
            }

            protected function pruneModelLockouts(int $days): int
            {
                throw new \RuntimeException('simulated-model-prune-error');
            }
        };
    });

    $this->artisan(PruneLockouts::class, ['--force' => true])
        ->assertExitCode(Command::FAILURE);
});
