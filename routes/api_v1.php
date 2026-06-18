<?php
// 📁 File: routes/api.php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\v1\AuthenticationController;
use App\Http\Controllers\Api\v1\PropertyController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\AgencyController;
use App\Http\Controllers\Api\v1\LeadController;
use App\Http\Controllers\Api\v1\LeadKanbanController;
use App\Http\Controllers\Api\v1\DepositController;
use App\Http\Controllers\Api\v1\EscrowController;       
use App\Http\Controllers\Api\v1\EscrowWebhookController;

/*
|--------------------------------------------------------------------------
| 🔓 Public / Unprotected V1 Infrastructure Endpoints
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/register', [AuthenticationController::class, 'register']);
Route::post('/escrow/webhook', [EscrowWebhookController::class, 'handleWebhook']);

// ⚡ TEMPORARILY EXPOSED FOR TESTING (Bypasses token enforcement)
Route::post('/deposit/initialize', [DepositController::class, 'initializeDeposit']);
Route::get('/escrow', [EscrowController::class, 'index']);
Route::post('/escrow', [EscrowController::class, 'store']);
Route::get('/escrow/{id}', [EscrowController::class, 'show']);
Route::post('/escrow/release', [EscrowController::class, 'release']);
Route::post('/escrow/refund', [EscrowController::class, 'refund']);


/*
|--------------------------------------------------------------------------
| 🔒 Protected V1 Infrastructure Endpoints (Sanctum Authenticated)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticationController::class, 'logout']);
    Route::get('/me', [AuthenticationController::class, 'me']);

    // Core Business Matrices
    Route::apiResource('properties', PropertyController::class);
    Route::apiResource('leads', LeadController::class);
    Route::patch('leads/{lead}/kanban', [LeadKanbanController::class, 'update']);
    
    // M-Pesa Callbacks via Daraja
    Route::post('/payments/stk-push', [PaymentController::class, 'stkPush']);
    Route::get('/payments/status/{checkoutRequestID}', [PaymentController::class, 'checkStatus']);
    
    // Workspace Onboarding
    Route::post('/vault/initialize-workspace', [AgencyController::class, 'store']);
    Route::post('/agency/join', [AgencyController::class, 'join']);
    Route::get('/agency', [AgencyController::class, 'show']);
    Route::put('/agency/{agency}', [AgencyController::class, 'update']);
});