<?php

namespace Beliven\Lockout\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCannotAccessWhenLocked
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()) {
            return $next($request);
        }

        // PHPStan/Psalm type assertion:
        /** @var \Beliven\Lockout\Contracts\LockableModel $user */
        $user = $request->user();

        if (!$user->isLockedOut()) {
            return $next($request);
        }

        return $this->lockedResponse();
    }

    /**
     * Build a standardized JSON response for locked accounts.
     */
    protected function lockedResponse(): JsonResponse
    {
        return response()->json([
            'message' => trans('lockout::lockout.middleware.account_locked'),
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
