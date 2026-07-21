<?php

use Illuminate\Support\Facades\Route;
use App\Services\Breet\BreetService;

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