<?php

use Beliven\Lockout\Commands\PruneLockouts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('prunes old lockout_logs and unlocked model_lockouts according to retention config', function () {
    // Determine configured retention (use defaults if config is not present)
    $daysLogs = (int) config('lockout.prune.lockout_logs_days', 90);
    $daysModels = (int) config('lockout.prune.model_lockouts_days', 365);

    // Cutoffs older than configured retention (ensure inserted records are candidates for pruning)
    $oldLogTimestamp = Carbon::now()->subDays($daysLogs + 1);
    $oldModelUnlockedAt = Carbon::now()->subDays($daysModels + 1);

    // Insert a lockout_log older than the cutoff
    DB::table('lockout_logs')->insert([
        'identifier'   => 'prune-test@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => $oldLogTimestamp,
        'created_at'   => Carbon::now(),
        'updated_at'   => Carbon::now(),
    ]);

    // Insert a model_lockout that has been unlocked and is older than the cutoff.
    // Provide minimal required polymorphic fields.
    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 1,
        'locked_at'   => $oldModelUnlockedAt->copy()->subMinutes(5),
        'unlocked_at' => $oldModelUnlockedAt,
        'expires_at'  => null,
        'reason'      => 'prune-test',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    // Sanity: ensure the records are present before pruning
    $this->assertTrue(DB::table('lockout_logs')->where('identifier', 'prune-test@example.test')->exists());
    $this->assertTrue(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 1)->exists());

    // Run the prune command non-interactively
    $this->artisan(PruneLockouts::class, ['--force' => true])->assertExitCode(0);

    // After pruning the old entries should be gone
    $this->assertFalse(DB::table('lockout_logs')->where('identifier', 'prune-test@example.test')->exists());
    $this->assertFalse(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 1)->exists());
});
