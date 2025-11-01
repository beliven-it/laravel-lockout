<?php

namespace Beliven\Lockout\Http\Controllers;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Http\Request;

class UnlockController
{
    public function __invoke(Request $request)
    {
        $identifier = $request->query('identifier');

        $model = Lockout::getLoginModel($identifier);

        if (!$model) {
            return redirect()->route('login')->withErrors(__('Account not found.'));
        }

        // Prefer a model-provided `unlock()` method if available, otherwise fall back
        // to clearing the persistent flag directly.
        if (method_exists($model, 'unlock')) {
            $model->unlock();
        } else {
            // Prefer clearing a persistent lock record when available (non-invasive).
            // Try model helpers/relations in this order:
            // 1. `activeLock()` helper that returns a ModelLockout instance.
            // 2. `lockouts()` relation to find active locks.
            // 3. Fallback to legacy `locked_at` attribute if present.
            try {
                if (method_exists($model, 'activeLock')) {
                    $lock = $model->activeLock();
                    if ($lock) {
                        // Prefer a helper method on the lock record if present.
                        if (method_exists($lock, 'markUnlocked')) {
                            $lock->markUnlocked();
                        } else {
                            $lock->unlocked_at = now();
                            $lock->save();
                        }
                    }
                } elseif (method_exists($model, 'lockouts')) {
                    // Query for active locks and mark them unlocked.
                    $active = $model->lockouts()
                        ->whereNull('unlocked_at')
                        ->where(function ($q) {
                            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        })
                        ->get();

                    foreach ($active as $a) {
                        $a->unlocked_at = now();
                        $a->save();
                    }
                }
            } catch (\Throwable $e) {
                // Never let exceptions break the flow of the application. Swallow errors.
            }
        }

        return redirect()->route('login')->with('status', __('Your account has been unlocked. You can now log in.'));
    }
}
