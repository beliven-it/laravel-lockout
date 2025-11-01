<?php

namespace Beliven\Lockout\Http\Middleware;

use Beliven\Lockout\Facades\Lockout;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotLocked
{
    public function handle($request, Closure $next)
    {
        $identifier = $request->input(Lockout::getLoginField());

        if (is_null($identifier)) {
            return $next($request);
        }

        // If a model exists for the identifier and it has an active persistent lock,
        // short-circuit with a 429 response.
        $model = Lockout::getLoginModel($identifier);

        if ($model && $this->modelHasActiveLock($model)) {
            return $this->lockedResponse();
        }

        // Otherwise, fall back to the in-memory/cache attempt counter.
        if (Lockout::hasTooManyAttempts($identifier)) {
            return $this->lockedResponse();
        }

        return $next($request);
    }

    /**
     * Inspect the model for an active lock using available helpers.
     *
     * Returns true if an active lock exists, false otherwise.
     */
    protected function modelHasActiveLock(Model $model): bool
    {
        // Prefer model-provided helper if available.
        if (method_exists($model, 'activeLock')) {
            try {
                return $model->activeLock() !== null;
            } catch (\Throwable $e) {
                // Ignore errors and treat as not locked to keep middleware resilient.
                return false;
            }
        }

        // Fallback to querying the lockouts relation if present.
        if (method_exists($model, 'lockouts')) {
            try {
                return (bool) $model->lockouts()
                    ->whereNull('unlocked_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists();
            } catch (\Throwable $e) {
                // Ignore DB errors and treat as not locked.
                return false;
            }
        }

        return false;
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
