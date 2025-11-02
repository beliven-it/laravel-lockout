<?php

namespace Beliven\Lockout\Contracts;

use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contract for models that support lockout functionality.
 *
 * Keep this minimal: only declare the methods used by the package so static
 * analysis (PHPStan) can rely on stable method signatures instead of Eloquent's
 * dynamic magic / builder methods.
 */
interface LockableModel
{
    /**
     * Morph relation to the lockout logs associated with this model.
     *
     * The concrete return may be an Eloquent `MorphMany` relation or a test/helper
     * object that mimics relation behavior (exists/create/first). We intentionally
     * do not enforce a concrete return type here to allow lightweight test stubs
     * to override this method without a strict signature conflict.
     *
     * @return mixed
     */
    public function lockoutLogs();

    /**
     * Morph relation to the lock records (ModelLockout) associated with this model.
     *
     * The concrete return may be an Eloquent `MorphMany` relation or a test/helper
     * object that mimics relation behavior (exists/create/first). We intentionally
     * do not enforce a concrete return type here to allow lightweight test stubs
     * to override this method without a strict signature conflict.
     *
     * @return mixed
     */
    public function lockouts();

    /**
     * Return the currently active lock record for this model, or null when none exists.
     */
    public function activeLock();

    /**
     * Determine whether the model currently has an active lock.
     */
    public function hasActiveLock(): bool;

    /**
     * Determine if the model is considered locked (either via persistent lock or attempt counters).
     */
    public function isLockedOut(): bool;

    /**
     * Create a new lock record for this model.
     *
     * The signature accepts an array to avoid collision with Builder::lock(bool|string).
     *
     * @param  array<string,mixed>  $options
     */
    public function lock(array $options = []);

    /**
     * Unlock the model.
     *
     * @param  array<string,mixed>  $options
     */
    public function unlock(array $options = []);
}
