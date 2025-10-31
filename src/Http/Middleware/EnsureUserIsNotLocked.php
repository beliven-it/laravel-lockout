<?php

namespace Beliven\Lockout\Http\Middleware;

use Beliven\Lockout\Facades\Lockout;

class EnsureUserIsNotLocked
{
    public function handle($request, \Closure $next)
    {
        $identifier = $request->input(Lockout::getLoginField());
        if (is_null($identifier)) {
            return $next($request);
        }

        // First, check the persistent model flag (blocked_at) if a model exists.
        // This ensures that once a model has been marked blocked we deny access
        // immediately without relying solely on the cache state.
        $model = Lockout::getLoginModel($identifier);
        if ($model && isset($model->blocked_at) && $model->blocked_at) {
            return response()->json([
                'message' => __('Account blocked due to too many login attempts'),
            ], 429);
        }

        // Fallback to checking the in-memory/cache attempt counter. This covers
        // cases where the cache threshold was exceeded but the persistent model
        // hasn't been updated yet.
        if (Lockout::hasTooManyAttempts($identifier)) {
            return response()->json([
                'message' => __('Account blocked due to too many login attempts'),
            ], 429);
        }

        return $next($request);
    }
}
