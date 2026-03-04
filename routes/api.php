<?php
// routes/api.php

use App\Http\Controllers\Api\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ----------------------------------------------------------------
    // Public Routes
    // ----------------------------------------------------------------

    // Rate limit global 6 req/menit sebagai jaring pengaman terakhir
    // (Tiered Freeze Logic di AuthService adalah penjaga utamanya)
    Route::post('/auth/login', [LoginController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('api.auth.login');

    // ----------------------------------------------------------------
    // Protected Routes
    // ----------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'Authenticated user.',
                'data'    => ['user' => $request->user()],
            ]);
        })->name('api.auth.me');

        Route::post('/auth/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful.',
            ]);
        })->name('api.auth.logout');
    });
});
