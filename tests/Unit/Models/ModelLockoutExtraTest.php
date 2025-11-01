<?php

use Beliven\Lockout\Commands\PruneLockouts;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Support\Facades\DB;

it('ModelLockout::active scope returns only currently active locks', function () {
    // Prepare records:
    // - unlocked_old: unlocked_at set -> not active
    $unlockedOld = new ModelLockout;
    $unlockedOld->model_type = 'users';
    $unlockedOld->model_id = 1;
    $unlockedOld->locked_at = now()->subDays(10);
    $unlockedOld->unlocked_at = now()->subDays(5);
    $unlockedOld->save();

    // - expired_old: expires_at in past -> not active
    $expiredOld = new ModelLockout;
    $expiredOld->model_type = 'users';
    $expiredOld->model_id = 2;
    $expiredOld->locked_at = now()->subDays(20);
    $expiredOld->expires_at = now()->subDays(1);
    $expiredOld->save();

    // - active_no_expiry: expires_at null and unlocked_at null -> active
    $activeNoExpiry = new ModelLockout;
    $activeNoExpiry->model_type = 'users';
    $activeNoExpiry->model_id = 3;
    $activeNoExpiry->locked_at = now()->subDay();
    $activeNoExpiry->save();

    // - active_future_expiry: expires_at in future -> active
    $activeFuture = new ModelLockout;
    $activeFuture->model_type = 'users';
    $activeFuture->model_id = 4;
    $activeFuture->locked_at = now()->subHour();
    $activeFuture->expires_at = now()->addDay();
    $activeFuture->save();

    $active = ModelLockout::active()->get();

    $ids = $active->pluck('id')->toArray();

    expect($ids)->toContain($activeNoExpiry->id);
    expect($ids)->toContain($activeFuture->id);
    expect($ids)->not->toContain($unlockedOld->id);
    expect($ids)->not->toContain($expiredOld->id);
});

it('ModelLockout::createActive creates a record with locked_at and is persisted', function () {
    $lock = ModelLockout::createActive([
        'model_type' => 'users',
        'model_id'   => 99,
    ]);

    // Returned instance should be persisted and have locked_at set
    expect($lock)->toBeInstanceOf(ModelLockout::class);
    expect($lock->id)->not->toBeNull();
    expect($lock->locked_at)->not->toBeNull();

    // Verify the DB contains the record
    $exists = DB::table('model_lockouts')->where('id', $lock->id)->exists();
    expect($exists)->toBeTrue();
});

it('prune command is a no-op when pruning is disabled via config', function () {
    // Disable pruning via config
    config()->set('lockout.prune.enabled', false);

    // Run the command and assert it returns success and prints the disabled message.
    $this->artisan(PruneLockouts::class)
        ->expectsOutput('Pruning is disabled via configuration (lockout.prune.enabled = false).')
        ->assertExitCode(0);
});

it('prune command fails when conflicting --only-logs and --only-model flags are provided', function () {
    // Passing both flags should produce an error and a FAILURE exit code.
    $this->artisan(PruneLockouts::class, [
        '--only-logs'  => true,
        '--only-model' => true,
    ])->expectsOutput('Options --only-logs and --only-model cannot be combined.')
        ->assertExitCode(1);
});
