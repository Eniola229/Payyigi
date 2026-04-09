<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Automatically logs sensitive API requests to the audit log.
 * Apply to financial and security-sensitive routes.
 */
class LogRequestToAudit
{
    public function handle(Request $request, Closure $next, string $event = 'api.request'): Response
    {
        $response = $next($request);

        // Only log if authenticated
        if ($request->user()) {
            AuditLog::record($event, [
                'new_values' => [
                    'method'      => $request->method(),
                    'path'        => $request->path(),
                    'status_code' => $response->getStatusCode(),
                ],
            ]);
        }

        return $response;
    }
}
