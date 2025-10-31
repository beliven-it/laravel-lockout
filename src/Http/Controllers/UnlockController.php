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
            // If the concrete model declares a public property `blocked_at` (used in tests),
            // set it directly. Otherwise rely on Eloquent's setAttribute so analyzers don't complain.
            if (property_exists($model, 'blocked_at')) {
                $model->blocked_at = null;
            } else {
                $model->setAttribute('blocked_at', null);
            }

            $model->save();
        }

        return redirect()->route('login')->with('status', __('Your account has been unlocked. You can now log in.'));
    }
}
