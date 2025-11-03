<?php

namespace Beliven\Lockout\Http\Controllers;

use Beliven\Lockout\Facades\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnlockController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $identifier = $this->resolveIdentifier($request);
        $model = Lockout::getLoginModel($identifier);

        if (!$model) {
            return $this->redirectWithError();
        }

        Lockout::unlockModel($model);

        return redirect()->route(config('lockout.unlock_redirect_route', 'login'))->with('status', trans('lockout::lockout.controller.account_unlocked'));
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
    protected function redirectWithError(): RedirectResponse
    {
        return redirect()->route(config('lockout.unlock_redirect_route', 'login'))->withErrors(trans('lockout::lockout.controller.model_not_found'));
    }
}
