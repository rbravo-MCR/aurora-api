<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login',  [AuthController::class, 'login']);   // email+password → envía OTP
Route::post('/auth/verify', [AuthController::class, 'verify']);  // email+OTP → token

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // …tus endpoints protegidos
    Route::get('/books', fn() => \App\Models\Book::paginate(20));
});
