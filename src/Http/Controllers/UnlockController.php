<?php

namespace Beliven\Lockout\Http\Controllers;

use Beliven\Lockout\Facades\Lockout;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnlockController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $identifier = $this->resolveIdentifier($request);
        $model = ModelLockout::active()
            ->where('identifier', $identifier)
            ->first()?->model;

        if (!$model) {
            return $this->redirectWithError();
        }

        // Delegate unlocking to a focused method; keep controller flow linear and easy to follow.
        $this->unlockModel($model, $identifier);

        return redirect()->route('login')->with('status', __('Your account has been unlocked. You can now log in.'));
    }

    /**
     * Resolve identifier from request query.
     */
    protected function resolveIdentifier(Request $request): ?string
    {
        return $request->query('identifier');
    }

    /**
     * Try to unlock the provided model using preferred APIs, then clear cache attempts.
     */
    protected function unlockModel($model, ?string $identifier): void
    {
        // Prefer model-provided API when available.
        try {
            if (method_exists($model, 'unlock')) {
                $model->unlock();
            } else {
                $this->unlockPersistentLocks($model);
            }
        } catch (\Throwable $_) {
            // Do not let model-specific exceptions break the unlock flow.
        }

        // Best-effort: clear the throttle counter so the user can attempt to log in again.
        $this->clearAttemptsSafely($identifier);
    }

    /**
     * Unlock persistent lock records when a model-level unlock method is not present.
     *
     * This tries:
     *  - model->activeLock() if available (preferred),
     *  - otherwise queries model->lockouts() for any active locks and marks them unlocked.
     */
    protected function unlockPersistentLocks($model): void
    {
        try {
            if (method_exists($model, 'activeLock')) {
                $lock = $model->activeLock();
                if ($lock) {
                    if (method_exists($lock, 'markUnlocked')) {
                        $lock->markUnlocked();

                        return;
                    }

                    $lock->unlocked_at = now();
                    $lock->save();

                    return;
                }
            }

            if (method_exists($model, 'lockouts')) {
                $active = $model->lockouts()
                    ->whereNull('unlocked_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->get();

                foreach ($active as $a) {
                    try {
                        if (method_exists($a, 'markUnlocked')) {
                            $a->markUnlocked();
                        } else {
                            $a->unlocked_at = now();
                            $a->save();
                        }
                    } catch (\Throwable $_) {
                        // Continue with other records on failure.
                    }
                }
            }
        } catch (\Throwable $_) {
            // Swallow errors: unlocking should be best-effort and never throw to callers.
        }
    }

    /**
     * Clear cached attempt counter for the identifier without throwing.
     */
    protected function clearAttemptsSafely(?string $identifier): void
    {
        if (empty($identifier)) {
            return;
        }

        try {
            Lockout::clearAttempts((string) $identifier);
        } catch (\Throwable $_) {
            // Ignore failures when clearing cache.
        }
    }

    /**
     * Redirect to login with a user-friendly error when the model cannot be found.
     */
    protected function redirectWithError(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(__('Account not found.'));
    }
}
