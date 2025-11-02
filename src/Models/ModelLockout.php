<?php

namespace Beliven\Lockout\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Represents a lock applied to an Eloquent model.
 *
 * The record is polymorphic so it can be associated to any model via
 * `model_type` / `model_id`. An active lock is a record where `unlocked_at` is null
 * and `expires_at` is either null or in the future.
 *
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property \Illuminate\Support\Carbon $locked_at
 * @property \Illuminate\Support\Carbon|null $unlocked_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $reason
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static Builder active()
 */
class ModelLockout extends Model
{
    protected $table = 'model_lockouts';

    // Allow mass-assignment for these attributes when creating locks via relationships.
    protected $fillable = [
        'model_type',
        'model_id',
        'locked_at',
        'unlocked_at',
        'expires_at',
        'reason',
        'meta',
    ];

    // Casts for convenient handling of timestamps and json meta.
    protected $casts = [
        'locked_at'   => 'datetime',
        'unlocked_at' => 'datetime',
        'expires_at'  => 'datetime',
        'meta'        => 'array',
    ];

    /**
     * Keep created_at / updated_at timestamps.
     *
     * We keep them for auditability (when the lock record was created/updated).
     */
    public $timestamps = true;

    /**
     * Polymorphic relation to the locked model (e.g. User, Admin, Customer).
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether this lock is currently active.
     *
     * Active = not unlocked AND (no expiry set OR expiry in the future).
     */
    public function isActive(): bool
    {
        // If unlocked_at is set, it's no longer active
        if ($this->unlocked_at !== null) {
            return false;
        }

        // If no expires_at, it's active
        if ($this->expires_at === null) {
            return true;
        }

        // Otherwise active only if expires_at is in the future
        return $this->expires_at->isFuture();
    }

    /**
     * Mark this lock as unlocked (set unlocked_at to now).
     *
     * Returns true when the model was successfully saved.
     */
    public function markUnlocked(): bool
    {
        $this->unlocked_at = now();

        return $this->save();
    }

    /**
     * Scope to only include currently active locks.
     *
     * Usage: ModelLockout::active()->where('model_type', ...)->where('model_id', ...)->exists();
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unlocked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    /**
     * Scope that returns records safe to prune relative to a cutoff timestamp.
     *
     * Selects records that either:
     *  - have been explicitly unlocked (unlocked_at not null) and unlocked_at < $cutoff, OR
     *  - have an expiry timestamp (expires_at not null) and expires_at < $cutoff (i.e. long-expired).
     *
     * This scope centralizes the pruning criteria so pruning commands and jobs
     * can reuse the exact same logic and remain consistent.
     */
    public function scopePrunable(Builder $query, Carbon $cutoff): Builder
    {
        return $query->where(function (Builder $q) use ($cutoff) {
            $q->where(function (Builder $q2) use ($cutoff) {
                $q2->whereNotNull('unlocked_at')
                    ->where('unlocked_at', '<', $cutoff);
            })->orWhere(function (Builder $q3) use ($cutoff) {
                $q3->whereNotNull('expires_at')
                    ->where('expires_at', '<', $cutoff);
            });
        });
    }

    /**
     * Create a new active lock record convenience helper.
     *
     * This is useful when using the relation (e.g. $model->lockouts()->create(...))
     * but kept here for symmetry and tests.
     */
    public static function createActive(array $attributes = []): self
    {
        $attributes['locked_at'] = $attributes['locked_at'] ?? now();

        return static::create($attributes);
    }
}
