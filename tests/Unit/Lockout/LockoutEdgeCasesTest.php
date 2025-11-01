<?php

use Beliven\Lockout\Lockout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Use in-memory array cache to avoid external dependencies and have deterministic state
    config()->set('lockout.cache_store', 'array');
    Cache::store('array')->flush();

    // Default threshold used in these edge-case tests
    config()->set('lockout.max_attempts', 3);
    config()->set('lockout.decay_minutes', 10);

    // Ensure lockout_logs table exists for createLog() calls
    Schema::dropIfExists('lockout_logs');
    Schema::create('lockout_logs', function (Blueprint $table) {
        $table->id();
        $table->nullableMorphs('model');
        $table->string('identifier')->index();
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        $table->timestamp('attempted_at')->nullable();
    });
});

afterEach(function () {
    // Guarded cache flush: if Cache::store() has been mocked to return an object
    // without a flush() method (tests that mock the facade), avoid calling it
    // directly to prevent method-not-found errors.
    try {
        $store = null;
        try {
            $store = Cache::store('array');
        } catch (\Throwable $_) {
            $store = null;
        }

        if (is_object($store) && method_exists($store, 'flush')) {
            try {
                $store->flush();
            } catch (\Throwable $_) {
                // ignore flush failures
            }
        }
    } catch (\Throwable $_) {
        // ignore any unexpected errors during cleanup
    }

    Schema::dropIfExists('lockout_logs');

    // Reset any auth provider override
    config()->set('auth.providers.users.model', null);
});

/**
 * A model whose `lockouts()->create()` throws to simulate relation creation failure.
 */
class RelationCreateThrowsModel extends Illuminate\Database\Eloquent\Model
{
    public function lockouts()
    {
        return new class
        {
            public function create($attributes)
            {
                throw new \Exception('relation create failed');
            }
        };
    }
}

/**
 * A model with no active lock present.
 */
class NoActiveLockModel extends Illuminate\Database\Eloquent\Model
{
    public function activeLock()
    {
        return null;
    }
}

/**
 * A model whose active lock object will throw when saved (to exercise save exception branch).
 */
class ActiveLockSaveThrowsModel extends Illuminate\Database\Eloquent\Model
{
    public function activeLock()
    {
        return new class extends \Beliven\Lockout\Models\ModelLockout
        {
            public function save(array $options = [])
            {
                throw new \Exception('save failed');
            }
        };
    }
}

/**
 * A model resolved by Lockout::getLoginModel but without a `notify()` method.
 * The static where() returns a query-like object exposing first().
 */
class ResolvedModelNoNotify extends Illuminate\Database\Eloquent\Model
{
    public static function where($field, $value)
    {
        return new class
        {
            public function first()
            {
                return new ResolvedModelNoNotify;
            }
        };
    }

    // Note: intentionally no notify() method
}

/**
 * A model class whose static where() throws an exception to simulate association failures.
 */
class BadResolverModel
{
    public static function where($field, $value)
    {
        throw new \Exception('resolver failure');
    }
}

describe('Lockout edge cases', function () {
    it('lockModel returns null when relation creation throws', function () {
        $service = app(Lockout::class);

        $model = new RelationCreateThrowsModel;

        $result = $service->lockModel($model, [
            'reason' => 'testing',
        ]);

        expect($result)->toBeNull();
    });

    it('unlockModel returns null when there is no active lock', function () {
        $service = app(Lockout::class);

        $model = new NoActiveLockModel;

        $result = $service->unlockModel($model, []);

        expect($result)->toBeNull();
    });

    it('unlockModel returns null when saving the lock throws an exception', function () {
        $service = app(Lockout::class);

        $model = new ActiveLockSaveThrowsModel;

        $result = $service->unlockModel($model, [
            'reason' => 'manual',
        ]);

        expect($result)->toBeNull();
    });

    it('attemptSendLockoutNotification is a no-op when resolved model lacks notify method', function () {
        // Ensure notifications are faked so we can assert none were sent
        Notification::fake();

        // Enable the feature and set the auth provider to our resolver that returns a non-notifiable model
        config()->set('lockout.unlock_via_notification', true);
        config()->set('auth.providers.users.model', ResolvedModelNoNotify::class);

        $service = app(Lockout::class);

        // Should not throw and should not send any notifications
        $service->attemptSendLockoutNotification('user@example.test', (object) ['ip' => '127.0.0.1']);

        Notification::assertNothingSent();
    });

    it('createLog swallows exceptions from model resolution and still saves a log record', function () {
        // Point the auth provider to a resolver that throws when queried
        config()->set('auth.providers.users.model', BadResolverModel::class);

        $service = app(Lockout::class);

        $identifier = 'bad-resolver@example.test';

        // Call attemptLockout which internally calls createLog and should swallow the resolver exception
        // Make sure notifications are disabled to avoid extra behavior
        config()->set('lockout.unlock_via_notification', false);

        $service->attemptLockout($identifier, (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit']);

        // Ensure a log was created despite the resolver throwing
        $log = DB::table('lockout_logs')->where('identifier', $identifier)->orderByDesc('id')->first();

        expect($log)->not->toBeNull();
        // Since association failed, morph columns should be null
        expect($log->model_type)->toBeNull();
        expect($log->model_id)->toBeNull();
    });

    it('incrementAttempts uses the put branch when increment returns falsy', function () {
        // Ensure the Lockout service uses a known cache store name
        config()->set('lockout.cache_store', 'array');

        // Build a mock cache repository that simulates increment returning falsy and expecting put to be called
        $mock = \Mockery::mock();
        $key = 'login-attempts:put-branch@example.test';

        $mock->shouldReceive('increment')
            ->once()
            ->with($key, 1)
            ->andReturn(false);

        $mock->shouldReceive('put')
            ->once()
            ->with(
                $key,
                1,
                \Mockery::on(function ($val) {
                    // third arg is a DateTime/Carbon instance or integer TTL depending on store;
                    // accept anything non-null
                    return $val !== null;
                })
            )
            ->andReturnNull();

        // Make the Cache facade return our mock when store() is called
        \Illuminate\Support\Facades\Cache::shouldReceive('store')->andReturn($mock);

        $service = app(Lockout::class);

        // This should exercise the branch where increment() was falsy and put() is used to initialize the key
        $service->incrementAttempts('put-branch@example.test');
    });

    it('unlockModel merges meta and actor, clears attempts and returns the lock when save succeeds', function () {
        // Use array cache for this test and seed some attempts to verify clearAttempts happens
        config()->set('lockout.cache_store', 'array');
        config()->set('lockout.login_field', 'email');

        Cache::store('array')->put('login-attempts:joe@example.test', 5, now()->addMinutes(10));

        $service = app(Lockout::class);

        // Create a lock-like object whose save() returns true
        $lock = new class extends \Beliven\Lockout\Models\ModelLockout
        {
            public $unlocked_at;

            public $meta = ['existing' => 'value'];

            public $reason;

            public function save(array $options = [])
            {
                return true;
            }
        };

        // Create a simple model that exposes the configured login field and returns the lock from activeLock()
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            public $email = 'joe@example.test';

            public $returnedLock;

            public function activeLock()
            {
                return $this->returnedLock;
            }
        };

        $model->returnedLock = $lock;

        // Call unlockModel with meta and actor options; also pass a requestData object to be forwarded to the event
        $result = $service->unlockModel($model, [
            'meta'        => ['new' => 'v'],
            'actor'       => 'admin',
            'requestData' => (object) ['ip' => '127.0.0.1'],
        ]);

        // The method should return the lock instance when save() succeeded
        expect($result)->toBe($lock);

        // Meta should have been merged and actor applied
        expect($lock->meta['existing'])->toBe('value');
        expect($lock->meta['new'])->toBe('v');
        expect($lock->meta['actor'])->toBe('admin');

        // Attempts for the identifier should have been cleared
        expect($service->getAttempts('joe@example.test'))->toBe(0);
    });
});
