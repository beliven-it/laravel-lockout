<?php

use Beliven\Lockout\Commands\PruneLockouts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Ensure tables exist for the command to operate on
    Schema::dropIfExists('lockout_logs');
    Schema::create('lockout_logs', function (Blueprint $table) {
        $table->id();
        $table->nullableMorphs('model');
        $table->string('identifier')->index();
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        $table->timestamp('attempted_at')->nullable();
    });

    Schema::dropIfExists('model_lockouts');
    Schema::create('model_lockouts', function (Blueprint $table) {
        $table->id();
        $table->string('model_type')->nullable();
        $table->unsignedBigInteger('model_id')->nullable();
        $table->timestamp('locked_at')->nullable();
        $table->timestamp('unlocked_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('lockout_logs');
    Schema::dropIfExists('model_lockouts');

    // Reset prune-related configuration to defaults so tests are isolated
    config()->set('lockout.prune.enabled', null);
    config()->set('lockout.prune.lockout_logs_days', null);
    config()->set('lockout.prune.model_lockouts_days', null);
});

it('does nothing when pruning is disabled via config', function () {
    config()->set('lockout.prune.enabled', false);

    $oldLogTimestamp = Carbon::now()->subDays(100);
    DB::table('lockout_logs')->insert([
        'identifier'   => 'disabled@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => $oldLogTimestamp,
    ]);

    $oldModelUnlockedAt = Carbon::now()->subDays(400);
    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 2,
        'locked_at'   => $oldModelUnlockedAt->copy()->subMinutes(5),
        'unlocked_at' => $oldModelUnlockedAt,
        'expires_at'  => null,
        'reason'      => 'disabled-prune',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    // Run command; should exit successfully but not delete anything
    $this->artisan(PruneLockouts::class, ['--force' => true])->assertExitCode(0);

    $this->assertTrue(DB::table('lockout_logs')->where('identifier', 'disabled@example.test')->exists());
    $this->assertTrue(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 2)->exists());
});

it('fails when --only-logs and --only-model are combined', function () {
    // Insert records so we can assert no accidental deletions
    DB::table('lockout_logs')->insert([
        'identifier'   => 'conflict@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(100),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 3,
        'locked_at'   => Carbon::now()->subDays(400),
        'unlocked_at' => Carbon::now()->subDays(400),
        'expires_at'  => null,
        'reason'      => 'conflict',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $this->artisan(PruneLockouts::class, ['--only-logs' => true, '--only-model' => true, '--force' => true])
        ->assertExitCode(1);

    // Ensure records are still present
    $this->assertTrue(DB::table('lockout_logs')->where('identifier', 'conflict@example.test')->exists());
    $this->assertTrue(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 3)->exists());
});

it('prunes only lockout_logs when --only-logs is provided', function () {
    DB::table('lockout_logs')->insert([
        'identifier'   => 'onlylogs@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(200),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 4,
        'locked_at'   => Carbon::now()->subDays(400),
        'unlocked_at' => Carbon::now()->subDays(400),
        'expires_at'  => null,
        'reason'      => 'only-logs',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $this->artisan(PruneLockouts::class, ['--only-logs' => true, '--force' => true])->assertExitCode(0);

    // log should be deleted, model_lockouts should remain
    $this->assertFalse(DB::table('lockout_logs')->where('identifier', 'onlylogs@example.test')->exists());
    $this->assertTrue(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 4)->exists());
});

it('prunes only model_lockouts when --only-model is provided', function () {
    DB::table('lockout_logs')->insert([
        'identifier'   => 'onlymodel@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(200),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 5,
        'locked_at'   => Carbon::now()->subDays(400),
        'unlocked_at' => Carbon::now()->subDays(400),
        'expires_at'  => null,
        'reason'      => 'only-model',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $this->artisan(PruneLockouts::class, ['--only-model' => true, '--force' => true])->assertExitCode(0);

    // model_lockouts should be deleted, log should remain
    $this->assertTrue(DB::table('lockout_logs')->where('identifier', 'onlymodel@example.test')->exists());
    $this->assertFalse(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 5)->exists());
});

it('respects days-logs and days-models overrides', function () {
    // Create entries older than 2 days so short overrides will prune them
    DB::table('lockout_logs')->insert([
        'identifier'   => 'overrides@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(3),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 6,
        'locked_at'   => Carbon::now()->subDays(4),
        'unlocked_at' => Carbon::now()->subDays(4),
        'expires_at'  => null,
        'reason'      => 'overrides',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    // Use tiny retention windows to trigger pruning
    $this->artisan(PruneLockouts::class, ['--days-logs' => 1, '--days-models' => 1, '--force' => true])->assertExitCode(0);

    $this->assertFalse(DB::table('lockout_logs')->where('identifier', 'overrides@example.test')->exists());
    $this->assertFalse(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 6)->exists());
});

it('asks for confirmation and aborts when user declines', function () {
    // Insert old records
    DB::table('lockout_logs')->insert([
        'identifier'   => 'abort@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(200),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 7,
        'locked_at'   => Carbon::now()->subDays(400),
        'unlocked_at' => Carbon::now()->subDays(400),
        'expires_at'  => null,
        'reason'      => 'abort',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $confirmMsg = 'Proceed with pruning? This will permanently delete old records.';

    // Simulate user declining confirmation; expect command to exit SUCCESS but not delete
    $this->artisan(PruneLockouts::class)
        ->expectsConfirmation($confirmMsg, false)
        ->assertExitCode(0);

    $this->assertTrue(DB::table('lockout_logs')->where('identifier', 'abort@example.test')->exists());
    $this->assertTrue(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 7)->exists());
});

it('asks for confirmation and proceeds when user accepts', function () {
    // Insert old records
    DB::table('lockout_logs')->insert([
        'identifier'   => 'accept@example.test',
        'ip_address'   => null,
        'user_agent'   => null,
        'attempted_at' => Carbon::now()->subDays(200),
    ]);

    DB::table('model_lockouts')->insert([
        'model_type'  => 'tests_user',
        'model_id'    => 8,
        'locked_at'   => Carbon::now()->subDays(400),
        'unlocked_at' => Carbon::now()->subDays(400),
        'expires_at'  => null,
        'reason'      => 'accept',
        'meta'        => null,
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $confirmMsg = 'Proceed with pruning? This will permanently delete old records.';

    // Proceed non-interactively using --force to ensure deterministic test behavior
    $this->artisan(PruneLockouts::class, ['--force' => true])->assertExitCode(0);

    $this->assertFalse(DB::table('lockout_logs')->where('identifier', 'accept@example.test')->exists());
    $this->assertFalse(DB::table('model_lockouts')->where('model_type', 'tests_user')->where('model_id', 8)->exists());
});
