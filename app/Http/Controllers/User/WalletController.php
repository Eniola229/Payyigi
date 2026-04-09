<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        return response()->json([
            'data' => [
                'currency'          => $wallet->currency,
                'balance'           => $wallet->balance,
                'locked_balance'    => $wallet->locked_balance,
                'available_balance' => $wallet->getAvailableBalance(),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->when($request->type,       fn($q) => $q->where('type', $request->type))
            ->when($request->entry_type, fn($q) => $q->where('entry_type', $request->entry_type))
            ->when($request->status,     fn($q) => $q->where('status', $request->status))
            ->when($request->from,       fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to,         fn($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $transactions]);
    }
}
