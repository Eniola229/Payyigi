<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NinVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BreetWebhookController;
use App\Http\Controllers\User\BankAccountController;
use App\Http\Controllers\User\BuyController;
use App\Http\Controllers\User\SellController;
use App\Http\Controllers\User\SwapController;
use App\Http\Controllers\User\WithdrawController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Webhooks (no auth — verified via HMAC) ───────────────────────────────────
Route::post('/webhooks/breet', [BreetWebhookController::class, 'handle']);

// ── Public Auth ──────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login',    LoginController::class);

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
         ->name('verification.verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
         ->middleware('throttle:3,10');

    Route::post('/password/forgot', [PasswordController::class, 'forgotPassword'])
         ->middleware('throttle:3,10');
    Route::post('/password/reset',  [PasswordController::class, 'resetPassword']);

    Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
         ->middleware('throttle:10,5');
});

// ── Authenticated Routes ─────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();
            \App\Models\AuditLog::record('auth.logout');
            return response()->json(['message' => 'Logged out.']);
        });
        Route::post('/logout-all', function (Request $request) {
            $request->user()->tokens()->delete();
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

        Route::get('/devices',           fn(Request $r) => response()->json(['data' => $r->user()->devices()->latest()->get()]));
        Route::delete('/devices/{device}', function (Request $r, \App\Models\UserDevice $d) {
            abort_if($d->user_id !== $r->user()->id, 403);
            $d->delete();
            return response()->json(['message' => 'Device removed.']);
        });
    });

    // NIN Verification
    Route::prefix('verification/nin')->group(function () {
        Route::post('/initiate', [NinVerificationController::class, 'initiateVerification'])->middleware('throttle:3,15');
        Route::post('/confirm',  [NinVerificationController::class, 'confirmVerification'])->middleware('throttle:5,15');
        Route::post('/resend',   [NinVerificationController::class, 'resendOtp'])->middleware('throttle:2,10');
    });

    // Profile & Wallet
    Route::get('/user/profile', fn(Request $r) => response()->json(['data' => $r->user()->load('wallet')]));
    Route::patch('/user/profile', function (Request $r) {
        $r->validate(['first_name' => 'sometimes|string|min:2|max:50', 'last_name' => 'sometimes|string|min:2|max:50', 'date_of_birth' => 'sometimes|date|before:-18 years']);
        $r->user()->update($r->only('first_name', 'last_name', 'date_of_birth'));
        return response()->json(['data' => $r->user()->fresh()]);
    });
    Route::get('/wallet', fn(Request $r) => response()->json(['data' => $r->user()->wallet]));

    // Notifications
    Route::get('/notifications', fn(Request $r) => response()->json(['data' => $r->user()->notifications()->paginate(20)]));
    Route::post('/notifications/{id}/read', function (Request $r, $id) {
        $r->user()->notifications()->find($id)?->markAsRead();
        return response()->json(['message' => 'Marked as read.']);
    });
    Route::post('/notifications/read-all', function (Request $r) {
        $r->user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'All marked as read.']);
    });

    // Transactions
    Route::get('/transactions', function (Request $r) {
        return response()->json([
            'data' => $r->user()->transactions()
                ->when($r->type,   fn($q) => $q->where('type', $r->type))
                ->when($r->status, fn($q) => $q->where('status', $r->status))
                ->latest()->paginate(20),
        ]);
    });
    Route::get('/transactions/{reference}', function (Request $r, string $ref) {
        $txn = $r->user()->transactions()->where('reference', $ref)->firstOrFail();
        return response()->json(['data' => $txn]);
    });

    // ── NIN-verified routes ───────────────────────────────────────────────────
    Route::middleware(['nin.verified'])->group(function () {

        // Bank accounts
        Route::prefix('bank-accounts')->group(function () {
            Route::get('/',                          [BankAccountController::class, 'index']);
            Route::post('/',                         [BankAccountController::class, 'store']);
            Route::delete('/{bankAccount}',          [BankAccountController::class, 'destroy']);
            Route::patch('/{bankAccount}/default',   [BankAccountController::class, 'setDefault']);
        });

        // Sell — credits wallet on completion
        Route::get('/sell/rate',       [SellController::class, 'getRate']);
        Route::post('/sell',           [SellController::class, 'initiate'])->middleware('txn.pin');
        Route::get('/sell/history',    [SellController::class, 'history']);
        Route::get('/sell/{reference}',[SellController::class, 'show']);

        // Buy — deducts wallet, sends crypto to user's external address
        Route::get('/buy/rate',        [BuyController::class, 'getRate']);
        Route::post('/buy',            [BuyController::class, 'initiate'])->middleware('txn.pin');
        Route::get('/buy/history',     [BuyController::class, 'history']);

        // Swap — crypto to crypto
        Route::get('/swap/rate',       [SwapController::class, 'getRate']);
        Route::post('/swap',           [SwapController::class, 'initiate'])->middleware('txn.pin');
        Route::get('/swap/history',    [SwapController::class, 'history']);

        // Withdraw — deducts wallet, sends NGN to bank
        Route::post('/withdraw',          [WithdrawController::class, 'initiate'])->middleware('txn.pin');
        Route::get('/withdraw/history',   [WithdrawController::class, 'history']);
    });
});
