<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\App as Tenant;
use App\Http\Controllers\Api\Auth;
use App\Http\Controllers\Api\PublicSite\ApplicationController as PublicApplicationController;
use App\Http\Controllers\Api\PublicSite\PlanController as PublicPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::prefix('public')->group(function () {
    Route::get('/plans', [PublicPlanController::class, 'index']);
    Route::post('/applications', [PublicApplicationController::class, 'store'])->middleware('throttle:5,1');
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [Auth\LoginController::class, 'store']);
    Route::post('/set-password', [Auth\SetPasswordController::class, 'store']);
    Route::post('/forgot-password', [Auth\PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/reset-password', [Auth\PasswordResetController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', Auth\MeController::class);
        Route::post('/logout', [Auth\LoginController::class, 'destroy']);
    });
});

Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', Admin\DashboardController::class);

    Route::get('/applications', [Admin\ApplicationController::class, 'index']);
    Route::get('/applications/{application}', [Admin\ApplicationController::class, 'show']);
    Route::patch('/applications/{application}', [Admin\ApplicationController::class, 'update']);
    Route::patch('/applications/{application}/status', [Admin\ApplicationController::class, 'updateStatus']);
    Route::delete('/applications/{application}', [Admin\ApplicationController::class, 'destroy']);
    Route::post('/applications/{application}/activities', [Admin\ApplicationController::class, 'storeActivity']);
    Route::post('/applications/{application}/whatsapp-link', [Admin\ApplicationController::class, 'whatsappLink']);
    Route::post('/applications/{application}/convert', [Admin\ApplicationController::class, 'convert']);

    Route::get('/plans', [Admin\PlanController::class, 'index']);
    Route::post('/plans', [Admin\PlanController::class, 'store']);
    Route::get('/plans/{plan}', [Admin\PlanController::class, 'show']);
    Route::patch('/plans/{plan}', [Admin\PlanController::class, 'update']);
    Route::post('/plans/{plan}/toggle', [Admin\PlanController::class, 'toggle']);
    Route::delete('/plans/{plan}', [Admin\PlanController::class, 'destroy']);

    Route::get('/payment-methods', [Admin\PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [Admin\PaymentMethodController::class, 'store']);
    Route::patch('/payment-methods/{paymentMethod}', [Admin\PaymentMethodController::class, 'update']);
    Route::post('/payment-methods/{paymentMethod}/toggle', [Admin\PaymentMethodController::class, 'toggle']);
    Route::delete('/payment-methods/{paymentMethod}', [Admin\PaymentMethodController::class, 'destroy']);

    Route::get('/companies', [Admin\CompanyController::class, 'index']);
    Route::get('/companies/{company}', [Admin\CompanyController::class, 'show']);
    Route::patch('/companies/{company}/status', [Admin\CompanyController::class, 'updateStatus']);
    Route::post('/companies/{company}/subscriptions', [Admin\CompanyController::class, 'subscriptions']);
    Route::post('/companies/{company}/resend-access', [Admin\CompanyController::class, 'resendAccess']);
    Route::post('/subscriptions/{subscription}/cancel', [Admin\CompanyController::class, 'cancelSubscription']);
});

Route::middleware(['auth:sanctum', 'role:business_admin,staff'])->prefix('app')->group(function () {
    Route::get('/me', Auth\MeController::class);
    Route::get('/subscription', Tenant\SubscriptionController::class);

    Route::middleware('subscription.active')->group(function () {
        Route::get('/dashboard', Tenant\DashboardController::class);
    });
});
