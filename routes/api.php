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
        Route::get('/reports/{kind}', [Tenant\ReportController::class, 'show']);

        Route::get('/tax-rates', [Tenant\TaxRateController::class, 'index']);
        Route::post('/tax-rates', [Tenant\TaxRateController::class, 'store']);
        Route::patch('/tax-rates/{taxRate}', [Tenant\TaxRateController::class, 'update']);
        Route::delete('/tax-rates/{taxRate}', [Tenant\TaxRateController::class, 'destroy']);

        Route::get('/categories', [Tenant\CategoryController::class, 'index']);
        Route::post('/categories', [Tenant\CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [Tenant\CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [Tenant\CategoryController::class, 'destroy']);

        Route::get('/payments', [Tenant\PaymentController::class, 'index']);

        Route::get('/payment-methods', [Tenant\CompanyPaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [Tenant\CompanyPaymentMethodController::class, 'store']);
        Route::patch('/payment-methods/{companyPaymentMethod}/active', [Tenant\CompanyPaymentMethodController::class, 'updateActive']);
        Route::patch('/payment-methods/{companyPaymentMethod}', [Tenant\CompanyPaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{companyPaymentMethod}', [Tenant\CompanyPaymentMethodController::class, 'destroy']);

        Route::get('/customers', [Tenant\CustomerController::class, 'index']);
        Route::post('/customers', [Tenant\CustomerController::class, 'store']);
        Route::patch('/customers/{customer}/active', [Tenant\CustomerController::class, 'updateActive']);
        Route::patch('/customers/{customer}', [Tenant\CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [Tenant\CustomerController::class, 'destroy']);

        Route::get('/suppliers', [Tenant\SupplierController::class, 'index']);
        Route::post('/suppliers', [Tenant\SupplierController::class, 'store']);
        Route::patch('/suppliers/{supplier}/active', [Tenant\SupplierController::class, 'updateActive']);
        Route::patch('/suppliers/{supplier}', [Tenant\SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [Tenant\SupplierController::class, 'destroy']);

        Route::get('/sales/preview', [Tenant\SaleController::class, 'preview']);
        Route::get('/sales', [Tenant\SaleController::class, 'index']);
        Route::post('/sales', [Tenant\SaleController::class, 'store']);
        Route::get('/sales/{sale}', [Tenant\SaleController::class, 'show']);
        Route::patch('/sales/{sale}', [Tenant\SaleController::class, 'update']);
        Route::post('/sales/{sale}/convert', [Tenant\SaleController::class, 'convert']);
        Route::patch('/sales/{sale}/payment', [Tenant\SaleController::class, 'updatePayment']);
        Route::post('/sales/{sale}/settle', [Tenant\SaleController::class, 'settle']);
        Route::patch('/sales/{sale}/settlements/{settlement}', [Tenant\SaleController::class, 'updateSettlement']);
        Route::post('/sales/{sale}/void', [Tenant\SaleController::class, 'void']);

        Route::get('/product-images', [Tenant\ProductImageController::class, 'index']);
        Route::post('/product-images', [Tenant\ProductImageController::class, 'store']);
        Route::get('/product-images/{productImage}/file', [Tenant\ProductImageController::class, 'file']);
        Route::patch('/product-images/{productImage}', [Tenant\ProductImageController::class, 'update']);
        Route::delete('/product-images/{productImage}', [Tenant\ProductImageController::class, 'destroy']);

        Route::get('/products', [Tenant\ProductController::class, 'index']);
        Route::post('/products', [Tenant\ProductController::class, 'store']);
        Route::post('/products/barcode', [Tenant\ProductController::class, 'generateBarcode']);
        Route::post('/products/import', Tenant\ProductImportController::class);
        Route::get('/products/{product}', [Tenant\ProductController::class, 'show']);
        Route::patch('/products/{product}', [Tenant\ProductController::class, 'update']);
        Route::delete('/products/{product}', [Tenant\ProductController::class, 'destroy']);
    });
});
