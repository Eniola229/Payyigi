<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    public function auditLogs(Request $request): JsonResponse
    {
        if (!$request->user('admin')->can('view_audit_logs')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $logs = AuditLog::when($request->event,   fn($q) => $q->where('event', 'like', "%{$request->event}%"))
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to,   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->paginate(50);

        return response()->json(['data' => $logs]);
    }

    public function webhookLogs(Request $request): JsonResponse
    {
        if (!$request->user('admin')->can('view_webhooks')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $logs = WebhookLog::when($request->status,     fn($q) => $q->where('status', $request->status))
            ->when($request->event_type, fn($q) => $q->where('event_type', $request->event_type))
            ->latest()
            ->paginate(50);

        return response()->json(['data' => $logs]);
    }
}