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

        $model->unlock();

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
     * Redirect to login with a user-friendly error when the model cannot be found.
     */
    protected function redirectWithError(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(__('Account not found.'));
    }
}
