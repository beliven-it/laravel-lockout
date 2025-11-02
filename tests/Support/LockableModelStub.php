<?php

namespace Beliven\Lockout\Tests\Support;

use Beliven\Lockout\Contracts\LockableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Simple test stub that extends Eloquent's Model and implements the
 * LockableModel contract required by the package.
 *
 * Purpose:
 * - Provide a minimal concrete type tests can extend instead of `Model`
 *   when they need an instance that is also recognized as `LockableModel`.
 * - Keep implementations intentionally minimal so individual tests can
 *   override behaviour as needed (for example providing a custom `lockouts()`
 *   relation object that implements `exists()`, `create()` or `first()`).
 *
 * Notes:
 * - Methods that would typically interact with the database return sensible
 *   defaults (null/false) or throw `BadMethodCallException` where appropriate
 *   to signal the test should provide an override if real behaviour is needed.
 */
class LockableModelStub extends Model implements LockableModel
{
    /**
     * By default test stub does not provide a real MorphMany relation.
     * Override this in tests if you need relation-like behaviour.
     *
     *
     * @throws \BadMethodCallException
     */
    public function lockoutLogs()
    {
        throw new \BadMethodCallException('lockoutLogs() not implemented on LockableModelStub. Override in test if needed.');
    }

    /**
     * By default test stub does not provide a real MorphMany relation.
     * Override this in tests if you need relation-like behaviour (exists/create/first).
     *
     *
     * @throws \BadMethodCallException
     */
    public function lockouts()
    {
        throw new \BadMethodCallException('lockouts() not implemented on LockableModelStub. Override in test if needed.');
    }

    /**
     * Return the currently active lock record for this model.
     * Default: none.
     */
    public function activeLock()
    {
        return null;
    }

    /**
     * Determine whether the model currently has an active lock.
     * Default: false.
     */
    public function hasActiveLock(): bool
    {
        return false;
    }

    /**
     * Determine if the model is considered locked (either via persistent lock
     * or attempt counters). Default: false.
     */
    public function isLockedOut(): bool
    {
        return false;
    }

    /**
     * Create/apply a new lock on this model.
     *
     * Tests that need to exercise real creation should override this method or
     * implement a custom `lockouts()` relation that supports `create()`.
     *
     * @param  array<string,mixed>  $options
     *
     * @throws \BadMethodCallException
     */
    public function lock(array $options = [])
    {
        throw new \BadMethodCallException('lock() not implemented on LockableModelStub. Override in test if needed.');
    }

    /**
     * Unlock the model by marking the active lock as unlocked.
     *
     * Default: no-op (returns null).
     *
     * @param  array<string,mixed>  $options
     */
    public function unlock(array $options = [])
    {
        return null;
    }
}
