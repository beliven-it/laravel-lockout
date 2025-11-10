<?php

namespace Beliven\Lockout\Http\Controllers;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LockController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $identifier = $this->resolveIdentifier($request);
        $model = Lockout::getLoginModel($identifier);

        if (!$model) {
            return $this->redirectWithError(trans('lockout::lockout.controller.model_not_found'));
        }

        try {
            Lockout::lockModel($model);

            Lockout::attemptSendLockoutNotification($identifier, (object) []);

            return redirect()->route(config('lockout.lock_redirect_route', 'login'))->with('status', trans('lockout::lockout.controller.account_locked'));
        } catch (\Exception $e) {
            return $this->redirectWithError(trans('lockout::lockout.controller.general_error'));
        }
    }

    /**
     * Resolve identifier from request query.
     */
    protected function resolveIdentifier(Request $request): ?string
    {
        return $request->query('identifier');
    }

    /**
     * Redirect to login with a user-friendly error when the model cannot be found.
     */
    protected function redirectWithError(string $error): RedirectResponse
    {
        return redirect()->route(config('lockout.lock_redirect_route', 'login'))->withErrors($error);
    }
}
