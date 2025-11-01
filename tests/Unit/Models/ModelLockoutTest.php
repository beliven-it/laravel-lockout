<?php

use Beliven\Lockout\Events\EntityUnlocked;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;

it('EntityUnlocked event holds model, lock, identifier and request data', function () {
    // Create a dummy Eloquent model instance (not persisted is fine for this DTO-like test)
    $model = new class extends EloquentModel
    {
        protected $table = 'users';
    };

    // Create a ModelLockout record to attach to the event (provide required morph fields)
    $lock = new ModelLockout;
    $lock->locked_at = now();
    $lock->model_type = 'users';
    $lock->model_id = 1;
    $lock->save();

    $identifier = 'user@example.test';
    $requestData = (object) ['ip' => '127.0.0.1'];

    $event = new EntityUnlocked($model, $lock, $identifier, $requestData);

    expect($event->model)->toBe($model);
    expect($event->modelLockout)->toBe($lock);
    expect($event->identifier)->toBe($identifier);
    expect($event->requestData)->toBe($requestData);
});

it('isActive returns false when unlocked_at is set', function () {
    $lock = new ModelLockout;
    $lock->unlocked_at = Carbon::now();

    expect($lock->isActive())->toBeFalse();
});

it('isActive returns true when expires_at is null and unlocked_at is null', function () {
    $lock = new ModelLockout;
    $lock->unlocked_at = null;
    $lock->expires_at = null;

    expect($lock->isActive())->toBeTrue();
});

it('isActive returns false when expires_at is in the past', function () {
    $lock = new ModelLockout;
    $lock->unlocked_at = null;
    $lock->expires_at = Carbon::now()->subHour();

    expect($lock->isActive())->toBeFalse();
});

it('isActive returns true when expires_at is in the future', function () {
    $lock = new ModelLockout;
    $lock->unlocked_at = null;
    $lock->expires_at = Carbon::now()->addHour();

    expect($lock->isActive())->toBeTrue();
});

it('markUnlocked sets unlocked_at and saves the model', function () {
    // Ensure required morph fields are provided so the record can be persisted in tests.
    $lock = new ModelLockout;
    $lock->meta = ['test' => true];
    $lock->model_type = 'users';
    $lock->model_id = 1;
    $lock->locked_at = now();
    $lock->save();

    $result = $lock->markUnlocked();

    expect($result)->toBeTrue();
    expect($lock->unlocked_at)->not->toBeNull();
});

it('scopePrunable selects unlocked older than cutoff and expired older than cutoff', function () {
    // Create three records: unlocked old, expired old, and recent active
    $oldUnlocked = new ModelLockout;
    $oldUnlocked->locked_at = now()->subDays(100);
    $oldUnlocked->unlocked_at = now()->subDays(80);
    $oldUnlocked->model_type = 'users';
    $oldUnlocked->model_id = 1;
    $oldUnlocked->save();

    $oldExpired = new ModelLockout;
    $oldExpired->locked_at = now()->subDays(200);
    $oldExpired->expires_at = now()->subDays(100);
    $oldExpired->model_type = 'users';
    $oldExpired->model_id = 1;
    $oldExpired->save();

    $recentActive = new ModelLockout;
    $recentActive->locked_at = now()->subDay();
    $recentActive->expires_at = now()->addDay();
    $recentActive->model_type = 'users';
    $recentActive->model_id = 1;
    $recentActive->save();

    $cutoff = now()->subDays(30);

    $prunable = ModelLockout::prunable($cutoff)->get();

    $ids = $prunable->pluck('id')->toArray();

    expect($ids)->toContain($oldUnlocked->id);
    expect($ids)->toContain($oldExpired->id);
    expect($ids)->not->toContain($recentActive->id);
});
