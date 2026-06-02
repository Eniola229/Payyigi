<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->bankAccounts()->whereNull('deleted_at')->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'bank_name'      => 'required|string|max:100',
            'bank_code'      => 'required|string|max:10',
            'account_number' => 'required|string|size:10|regex:/^\d{10}$/|unique:bank_accounts,account_number',
            'account_name'   => 'required|string|max:100',
        ]);

        $user = $request->user();

        if ($user->bankAccounts()->count() >= 5) {
            return response()->json(['message' => 'Maximum of 5 bank accounts allowed.'], 422);
        }

        $exists = $user->bankAccounts()
            ->where('account_number', $request->account_number)
            ->where('bank_code', $request->bank_code)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'This bank account has already been added.'], 422);
        }

        $isFirst = $user->bankAccounts()->count() === 0;

        $account = BankAccount::create([
            'user_id'        => $user->id,
            'bank_name'      => $request->bank_name,
            'bank_code'      => $request->bank_code,
            'account_number' => $request->account_number,
            'account_name'   => $request->account_name,
            'is_default'     => $isFirst,
            'is_verified'    => true,
            'verified_at'    => now(),
        ]);

        AuditLog::record('user.bank_account_added', [
            'user_id'    => $user->id,
            'new_values' => ['bank_code' => $request->bank_code, 'account_last4' => substr($request->account_number, -4)],
        ]);

        return response()->json(['message' => 'Bank account added.', 'data' => $account], 201);
    }

    public function setDefault(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_if($bankAccount->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($request, $bankAccount) {
            $request->user()->bankAccounts()->update(['is_default' => false]);
            $bankAccount->update(['is_default' => true]);
        });

        return response()->json(['message' => 'Default bank account updated.']);
    }

    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        abort_if($bankAccount->user_id !== $request->user()->id, 403);

        $hasPending = Transaction::where('bank_account_id', $bankAccount->id)
            ->whereIn('status', ['pending', 'processing', 'awaiting_crypto'])
            ->exists();

        if ($hasPending) {
            return response()->json(['message' => 'Cannot remove account with pending transactions.'], 422);
        }

        $bankAccount->delete();

        AuditLog::record('user.bank_account_removed', ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Bank account removed.']);
    }
}
