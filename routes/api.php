<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NinVerificationController;
use App\Http\Controllers\Auth\BvnVerificationController;
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

// ── Webhooks (no auth) ───────────────────────────────────
Route::post('/webhooks/korapay', [\App\Http\Controllers\KorapayWebhookController::class, 'handle']);
Route::post('/webhooks/breet', [\App\Http\Controllers\BreetWebhookController::class, 'handle']);

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

 
    // BVN Verification
    Route::prefix('verification/bvn')->group(function () {
        Route::post('/verify', [BvnVerificationController::class, 'verify'])
             ->middleware('throttle:3,30'); // 3 attempts per 30 min — matches controller throttle
    });

    // NIN Verification
    Route::prefix('verification/nin')->group(function () {
        Route::post('/initiate', [NinVerificationController::class, 'initiateVerification'])->middleware('throttle:3,15');
        Route::post('/confirm',  [NinVerificationController::class, 'confirmVerification'])->middleware('throttle:5,15');
        Route::post('/resend',   [NinVerificationController::class, 'resendOtp'])->middleware('throttle:2,10');
    });

    // Virtual account
    Route::prefix('wallet')->group(function () {
        Route::post('/virtual-account/generate', [\App\Http\Controllers\User\VirtualAccountController::class, 'generate']);
        Route::get('/virtual-account',           [\App\Http\Controllers\User\VirtualAccountController::class, 'show']);
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

    // ── Admin Routes ─────────────────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {

        // Public admin auth
        Route::post('/login', \App\Http\Controllers\Admin\Auth\AdminLoginController::class);

        // Authenticated admin routes
        Route::middleware(['auth:admin', 'admin.active'])->group(function () {

            Route::post('/logout', function (Request $request) {
                $request->user('admin')->currentAccessToken()->delete();
                return response()->json(['message' => 'Logged out.']);
            });

            // Dashboard — any authenticated admin
            Route::get('/dashboard', \App\Http\Controllers\Admin\DashboardController::class)
                 ->middleware('can:view_dashboard_stats,admin');
 
            // Users
            Route::prefix('users')->middleware('can:view_users,admin')->group(function () {
                Route::get('/',                     [\App\Http\Controllers\Admin\UserManagementController::class, 'index']);
                Route::get('/{user}',               [\App\Http\Controllers\Admin\UserManagementController::class, 'show']);
                Route::get('/{user}/transactions',  [\App\Http\Controllers\Admin\UserManagementController::class, 'transactions']);
                Route::post('/{user}/suspend',      [\App\Http\Controllers\Admin\UserManagementController::class, 'suspend']);
                Route::post('/{user}/unsuspend',    [\App\Http\Controllers\Admin\UserManagementController::class, 'unsuspend']);
            });

            // Transactions
            Route::prefix('transactions')->middleware('can:view_transactions,admin')->group(function () {
                Route::get('/',                         [\App\Http\Controllers\Admin\TransactionMonitorController::class, 'index']);
                Route::get('/pending-withdrawals',      [\App\Http\Controllers\Admin\TransactionMonitorController::class, 'pendingWithdrawals']);
                Route::get('/revenue',                  [\App\Http\Controllers\Admin\TransactionMonitorController::class, 'revenue']);
                Route::get('/{transaction}',            [\App\Http\Controllers\Admin\TransactionMonitorController::class, 'show']);
                Route::post('/{transaction}/flag',      [\App\Http\Controllers\Admin\TransactionMonitorController::class, 'flag']);
            });

            // Fraud
            Route::prefix('fraud')->middleware('can:view_fraud_flags,admin')->group(function () {
                Route::get('/',                     [\App\Http\Controllers\Admin\FraudController::class, 'index']);
                Route::get('/{fraudFlag}',          [\App\Http\Controllers\Admin\FraudController::class, 'show']);
                Route::patch('/{fraudFlag}/resolve',[\App\Http\Controllers\Admin\FraudController::class, 'resolve']);
                Route::post('/users/{user}/flag',   [\App\Http\Controllers\Admin\FraudController::class, 'flagUser']);
            });

            // Logs
            Route::get('/audit-logs',   [\App\Http\Controllers\Admin\LogsController::class, 'auditLogs']);
            Route::get('/webhook-logs', [\App\Http\Controllers\Admin\LogsController::class, 'webhookLogs']);

            // Admin management — super_admin only
            Route::prefix('admins')->middleware('role:super_admin')->group(function () {
                Route::get('/',                     [\App\Http\Controllers\Admin\AdminManagementController::class, 'index']);
                Route::get('/roles',                [\App\Http\Controllers\Admin\AdminManagementController::class, 'roles']);
                Route::post('/',                    [\App\Http\Controllers\Admin\AdminManagementController::class, 'store']);
                Route::patch('/{admin}/role',       [\App\Http\Controllers\Admin\AdminManagementController::class, 'updateRole']);
                Route::patch('/{admin}/toggle',     [\App\Http\Controllers\Admin\AdminManagementController::class, 'toggleActive']);
            });
        });
    });
}); 
