<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates transaction_pin on any request that moves money.
 * Apply to: sell, withdraw, swap, convert, transfer routes.
 *
 * The PIN must be sent in the request body as `transaction_pin`.
 */
class RequireTransactionPin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // User hasn't set a PIN yet
        if (!$user->transaction_pin) {
            return response()->json([
                'message' => 'Please set a transaction PIN before making transactions.',
                'action'  => 'set_transaction_pin',
            ], 403);
        }

        $pin = $request->input('transaction_pin');

        if (!$pin) {
            return response()->json([
                'message' => 'Transaction PIN is required.',
            ], 422);
        }

        // Throttle wrong PIN attempts: 5 attempts per 15 minutes
        $throttleKey = "txn_pin:{$user->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            AuditLog::record('security.transaction_pin_locked', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => "Too many incorrect PIN attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        if (!$user->verifyTransactionPin($pin)) {
            RateLimiter::hit($throttleKey, 60 * 15);

            $attempts   = RateLimiter::attempts($throttleKey);
            $remaining  = max(0, 5 - $attempts);

            AuditLog::record('security.transaction_pin_failed', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message'   => "Incorrect transaction PIN. {$remaining} attempt(s) remaining.",
            ], 422);
        }

        RateLimiter::clear($throttleKey);

        return $next($request);
    }
}
