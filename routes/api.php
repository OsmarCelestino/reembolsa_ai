<?php

use App\Http\Controllers\Api\ReimbursementController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/tokens/current', [TokenController::class, 'destroy']);

    Route::get('/reimbursements', [ReimbursementController::class, 'index']);
    Route::post('/reimbursements', [ReimbursementController::class, 'store'])->middleware('throttle:reimbursements');
    Route::get('/reimbursements/{reimbursement}', [ReimbursementController::class, 'show']);
    Route::post('/reimbursements/{reimbursement}/review', [ReimbursementController::class, 'review']);
});
