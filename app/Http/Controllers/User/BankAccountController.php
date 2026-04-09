<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->bankAccounts()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    /**
     * Verify account name from bank before saving.
     * GET /bank-accounts/verify?account_number=0123456789&bank_code=044
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'account_number' => 'required|string|regex:/^\d{10}$/',
            'bank_code'      => 'required|string',
        ]);

        $cacheKey = "bank_verify:{$request->account_number}:{$request->bank_code}";

        try {
            $result = Cache::remember($cacheKey, now()->addHours(24), function () use ($request) {
                return $this->breet->verifyBankAccount($request->account_number, $request->bank_code);
            });

            return response()->json([
                'data' => [
                    'account_number' => $result['account_number'],
                    'account_name'   => $result['account_name'],
                    'bank_code'      => $request->bank_code,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not verify account. Please check the details and try again.',
            ], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'bank_name'      => 'required|string|max:100',
            'bank_code'      => 'required|string|max:10',
            'account_number' => 'required|string|regex:/^\d{10}$/',
            'account_name'   => 'required|string|max:100',
        ]);

        $user = $request->user();

        if ($user->bankAccounts()->count() >= 5) {
            return response()->json([
                'message' => 'Maximum of 5 bank accounts allowed.',
            ], 422);
        }

        $exists = $user->bankAccounts()
            ->where('account_number', $request->account_number)
            ->where('bank_code', $request->bank_code)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'This bank account is already saved.'], 422);
        }

        $isFirst = $user->bankAccounts()->count() === 0;

        $account = DB::transaction(function () use ($request, $user, $isFirst) {
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

            AuditLog::record('bank_account.added', [
                'user_id'    => $user->id,
                'new_values' => [
                    'bank_name'     => $request->bank_name,
                    'account_last4' => substr($request->account_number, -4),
                ],
            ]);

            return $account;
        });

        return response()->json([
            'message' => 'Bank account added successfully.',
            'data'    => $account,
        ], 201);
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

        $hasPending = $bankAccount->user->transactions()
            ->where('bank_account_id', $bankAccount->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPending) {
            return response()->json([
                'message' => 'Cannot remove this account while there are pending transactions.',
            ], 422);
        }

        $wasDefault = $bankAccount->is_default;
        $bankAccount->delete();

        if ($wasDefault) {
            $request->user()->bankAccounts()->latest()->first()?->update(['is_default' => true]);
        }

        AuditLog::record('bank_account.removed', [
            'user_id'    => $request->user()->id,
            'new_values' => ['account_last4' => substr($bankAccount->account_number, -4)],
        ]);

        return response()->json(['message' => 'Bank account removed.']);
    }

    public function banks(): JsonResponse
    {
        $banks = Cache::remember('nigerian_banks', now()->addDay(), function () {
            return $this->breet->getBanks();
        });

        return response()->json(['data' => $banks]);
    }
}
