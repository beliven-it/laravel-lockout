<?php

use Beliven\Lockout\Events\EntityUnlocked;
use Beliven\Lockout\Lockout;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

it('creates a persistent lock for a user, unlocks it via Lockout::unlockModel, clears attempts and dispatches EntityUnlocked', function () {
    // Use array cache to keep tests deterministic
    config()->set('lockout.cache_store', 'array');
    Cache::store('array')->flush();

    // Ensure login_field is email for the fixture
    config()->set('lockout.login_field', 'email');

    // Prepare database tables used by the integration flow
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

    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->timestamp('locked_at')->nullable();
        $table->timestamps();
    });

    // Create a notifiable user fixture
    $user = User::query()->create([
        'email'    => 'integration@example.test',
        'password' => 'secret',
    ]);

    /** @var Lockout $service */
    $service = app(Lockout::class);

    // Create a persistent lock via the trait helper (delegates to Lockout::lockModel)
    $createdLock = $user->lock(['reason' => 'integration-test']);
    expect($createdLock)->not->toBeNull();

    // Sanity: ensure an active lock exists in DB
    $active = DB::table('model_lockouts')
        ->where('model_type', User::class)
        ->where('model_id', $user->id)
        ->whereNull('unlocked_at')
        ->first();
    expect($active)->not->toBeNull();

    // Seed an attempt counter for this user's identifier so clearAttempts will run
    $service->incrementAttempts($user->email);
    expect($service->getAttempts($user->email))->toBe(1);

    // Fake events so we can assert EntityUnlocked dispatch
    Event::fake();

    // Perform unlock via service
    $result = $service->unlockModel($user, [
        'reason'      => 'manual-unlock',
        'requestData' => (object) ['ip' => '127.0.0.1'],
    ]);

    // Should return the lock object when saved successfully
    expect($result)->not->toBeNull();

    // Reload most recent lock row and assert unlocked_at was set
    $row = DB::table('model_lockouts')
        ->where('model_type', User::class)
        ->where('model_id', $user->id)
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->unlocked_at)->not->toBeNull();

    // Attempts counter for the user's identifier should have been cleared
    expect($service->getAttempts($user->email))->toBe(0);

    // Event should have been dispatched with the expected payload
    Event::assertDispatched(EntityUnlocked::class, function ($event) use ($user) {
        return $event->model instanceof User && $event->model->id === $user->id;
    });

    // Cleanup DB
    Schema::dropIfExists('model_lockouts');
    Schema::dropIfExists('users');
});
