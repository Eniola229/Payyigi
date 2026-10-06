<?php

use Illuminate\Support\Facades\Route;
use App\Services\Breet\BreetService;
use App\Http\Controllers\Support\SupportAccessController;
use App\Http\Controllers\Support\SupportChatController;
use App\Http\Middleware\EnsureSupportSession;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/test/breet/banks', function () {
    try {
        $banks = app(BreetService::class)->getBanks();
        return response()->json(['count' => count($banks), 'banks' => $banks]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::prefix('support')->group(function () {
    Route::get('/', [SupportAccessController::class, 'landing'])->name('support.landing');

     Route::post('/request', [SupportAccessController::class, 'request'])
         ->middleware('throttle:5,10')->name('support.request');

    Route::get('/verify/{token}', [SupportAccessController::class, 'verify'])
         ->middleware('throttle:20,1')->name('support.verify');

    Route::middleware(EnsureSupportSession::class)->group(function () {
        Route::post('/logout', [SupportAccessController::class, 'logout']);
        Route::get('/portal', [SupportChatController::class, 'portal'])->name('support.portal');

        Route::get('/tickets',       [SupportChatController::class, 'tickets']);
        Route::post('/tickets',      [SupportChatController::class, 'store'])->middleware('throttle:10,60');
        Route::get('/tickets/{id}',  [SupportChatController::class, 'show']);
        Route::post('/tickets/{id}/messages', [SupportChatController::class, 'send'])->middleware('throttle:20,1');
    });
});
