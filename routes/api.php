<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login',  [AuthController::class, 'login']);   // email+password → envía OTP
Route::post('/auth/verify', [AuthController::class, 'verify']);  // email+OTP → token
//Route::get('/ping', fn() => response()->json(['ok' => true]));
Route::post('/auth/register',[AuthController::class, 'register']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // …tus endpoints protegidos
   Route::get('/me', fn(\Illuminate\Http\Request $r) => $r->user());
});
