<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NinVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BreetWebhookController;
use App\Http\Controllers\User\BankAccountController;
use App\Http\Controllers\User\SellOrderController;
use App\Http\Controllers\User\WalletController;
use App\Http\Controllers\User\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| API Routes — PayYigi
|--------------------------------------------------------------------------
*/

// ── Public Auth Routes ───────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login',    LoginController::class);

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('api.verification.verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
         ->middleware('throttle:3,10');

    Route::post('/password/forgot', [PasswordController::class, 'forgotPassword'])
         ->middleware('throttle:3,10');
    Route::post('/password/reset', [PasswordController::class, 'resetPassword']);

    Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
         ->middleware('throttle:10,5');
});

// ── Breet Webhooks (public — signature validated inside controller) ────────────
Route::post('/webhooks/breet', [BreetWebhookController::class, 'handle']);

// ── Authenticated Routes ─────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

    // ── Auth management ──────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();
            \App\Models\AuditLog::record('auth.logout');
            return response()->json(['message' => 'Logged out successfully.']);
        });

        Route::post('/logout-all', function (Request $request) {
            $request->user()->tokens()->delete();
            \App\Models\AuditLog::record('auth.logout_all');
            return response()->json(['message' => 'Logged out from all devices.']);
        });

        Route::post('/password/change', [PasswordController::class, 'changePassword']);
        Route::post('/pin/set',         [PasswordController::class, 'setTransactionPin']);
        Route::post('/pin/change',      [PasswordController::class, 'changeTransactionPin']);

        Route::prefix('two-factor')->group(function () {
            Route::post('/setup',   [TwoFactorController::class, 'setup']);
            Route::post('/enable',  [TwoFactorController::class, 'enable']);
            Route::post('/disable', [TwoFactorController::class, 'disable']);
        });

        Route::get('/devices', function (Request $request) {
            return response()->json([
                'data' => $request->user()->devices()
                    ->orderByDesc('last_used_at')
                    ->get(['id','device_name','device_type','browser','platform','ip_address','is_trusted','last_used_at']),
            ]);
        });
        Route::delete('/devices/{device}', function (Request $request, \App\Models\UserDevice $device) {
            abort_if($device->user_id !== $request->user()->id, 403);
            $device->delete();
            return response()->json(['message' => 'Device removed.']);
        });
    });

    // ── NIN Verification ─────────────────────────────────────────────────────
    Route::prefix('verification/nin')->group(function () {
        Route::post('/initiate', [NinVerificationController::class, 'initiateVerification'])
             ->middleware('throttle:3,15');
        Route::post('/confirm',  [NinVerificationController::class, 'confirmVerification'])
             ->middleware('throttle:5,15');
        Route::post('/resend',   [NinVerificationController::class, 'resendOtp'])
             ->middleware('throttle:2,10');
    });

    // ── Profile ───────────────────────────────────────────────────────────────
    Route::prefix('user')->group(function () {
        Route::get('/profile', function (Request $request) {
            return response()->json(['data' => $request->user()->load('wallet')]);
        });
        Route::patch('/profile', function (Request $request) {
            $request->validate([
                'first_name'    => 'sometimes|string|min:2|max:50|regex:/^[a-zA-Z\s\-]+$/',
                'last_name'     => 'sometimes|string|min:2|max:50|regex:/^[a-zA-Z\s\-]+$/',
                'date_of_birth' => 'sometimes|date|before:-18 years',
            ]);
            $request->user()->update($request->only('first_name', 'last_name', 'date_of_birth'));
            return response()->json(['message' => 'Profile updated.', 'data' => $request->user()->fresh()]);
        });
    });

    // ── Wallet ────────────────────────────────────────────────────────────────
    Route::prefix('wallet')->group(function () {
        Route::get('/balance',      [WalletController::class, 'balance']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
    });

    // ── Transactions (generic) ────────────────────────────────────────────────
    Route::get('/transactions', function (Request $request) {
        return response()->json([
            'data' => $request->user()->transactions()
                ->when($request->type,   fn($q) => $q->where('type', $request->type))
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()->paginate(20),
        ]);
    });
    Route::get('/transactions/{transaction}', function (Request $request, \App\Models\Transaction $transaction) {
        abort_if($transaction->user_id !== $request->user()->id, 403);
        return response()->json(['data' => $transaction->load('bankAccount')]);
    });

    // ── Bank Accounts ─────────────────────────────────────────────────────────
    Route::prefix('bank-accounts')->middleware('nin.verified')->group(function () {
        Route::get('/',                        [BankAccountController::class, 'index']);
        Route::get('/banks',                   [BankAccountController::class, 'banks']);
        Route::get('/verify',                  [BankAccountController::class, 'verify']);
        Route::post('/',                       [BankAccountController::class, 'store']);
        Route::delete('/{bankAccount}',        [BankAccountController::class, 'destroy']);
        Route::patch('/{bankAccount}/default', [BankAccountController::class, 'setDefault']);
    });

    // ── Sell Orders ───────────────────────────────────────────────────────────
    Route::prefix('sell')->middleware('nin.verified')->group(function () {
        Route::get('/assets',               [SellOrderController::class, 'supportedAssets']);
        Route::get('/rate',                 [SellOrderController::class, 'getRate']);
        Route::get('/orders',               [SellOrderController::class, 'index']);
        Route::get('/orders/{transaction}', [SellOrderController::class, 'show']);
        Route::post('/orders',              [SellOrderController::class, 'createOrder'])->middleware('txn.pin');
    });

    // ── Withdrawals ───────────────────────────────────────────────────────────
    Route::prefix('withdrawals')->middleware('nin.verified')->group(function () {
        Route::get('/',  [WithdrawalController::class, 'history']);
        Route::post('/', [WithdrawalController::class, 'withdraw'])->middleware('txn.pin');
    });

});
