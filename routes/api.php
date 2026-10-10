<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\App as Tenant;
use App\Http\Controllers\Api\Auth;
use App\Http\Controllers\Api\PublicSite\ApplicationController as PublicApplicationController;
use App\Http\Controllers\Api\PublicSite\PlanController as PublicPlanController;
use App\Http\Controllers\Api\PublicSite\InvoiceVerificationController;
use App\Http\Controllers\Api\PublicSite\SiteContentController as PublicSiteContentController;
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
    Route::get('/site-content', [PublicSiteContentController::class, 'show']);
    Route::get('/invoices/verify/{code}', [InvoiceVerificationController::class, 'show'])->middleware('throttle:30,1');
    Route::get('/invoices/verify/{code}/document', [InvoiceVerificationController::class, 'document'])->middleware('throttle:30,1');
    Route::post('/applications/otp', [PublicApplicationController::class, 'requestOtp'])->middleware('throttle:6,1');
    Route::post('/applications', [PublicApplicationController::class, 'store'])->middleware('throttle:10,1');
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [Auth\LoginController::class, 'store'])->middleware('throttle:30,1');
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

    Route::get('/site-content', [Admin\SiteContentController::class, 'index']);
    Route::post('/site-content/faqs', [Admin\SiteContentController::class, 'storeFaq']);
    Route::post('/site-content/faqs/reorder', [Admin\SiteContentController::class, 'reorderFaqs']);
    Route::patch('/site-content/faqs/{faq}', [Admin\SiteContentController::class, 'updateFaq']);
    Route::delete('/site-content/faqs/{faq}', [Admin\SiteContentController::class, 'destroyFaq']);
    Route::put('/site-content/pages/{slug}', [Admin\SiteContentController::class, 'updatePage']);

    Route::patch('/password', [Tenant\PasswordController::class, 'update']);
    Route::patch('/me/locale', [Admin\LocaleController::class, 'update']);
    Route::get('/companies', [Admin\CompanyController::class, 'index']);
    Route::get('/companies/{company}', [Admin\CompanyController::class, 'show']);
    Route::patch('/companies/{company}/status', [Admin\CompanyController::class, 'updateStatus']);
    Route::post('/companies/{company}/subscriptions', [Admin\CompanyController::class, 'subscriptions']);
    Route::post('/companies/{company}/resend-access', [Admin\CompanyController::class, 'resendAccess']);
    Route::post('/subscriptions/{subscription}/cancel', [Admin\CompanyController::class, 'cancelSubscription']);
});

Route::middleware(['auth:sanctum', 'role:business_admin,staff'])->prefix('app')->group(function () {
    Route::get('/me', Auth\MeController::class);
    Route::get('/subscription', Tenant\SubscriptionController::class)->middleware('role:business_admin');

    Route::middleware(['subscription.active', 'company.setup'])->group(function () {
        Route::get('/dashboard', Tenant\DashboardController::class)->middleware('permission:dashboard.view');
        Route::get('/reports/{kind}', [Tenant\ReportController::class, 'show'])->middleware('permission:reports.view');

        Route::get('/tax-rates', [Tenant\TaxRateController::class, 'index']);
        Route::post('/tax-rates', [Tenant\TaxRateController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('/tax-rates/{taxRate}', [Tenant\TaxRateController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('/tax-rates/{taxRate}', [Tenant\TaxRateController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('/recargo-rates', [Tenant\RecargoRateController::class, 'index']);
        Route::post('/recargo-rates', [Tenant\RecargoRateController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('/recargo-rates/{recargoRate}', [Tenant\RecargoRateController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('/recargo-rates/{recargoRate}', [Tenant\RecargoRateController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('/categories', [Tenant\CategoryController::class, 'index']);
        Route::post('/categories', [Tenant\CategoryController::class, 'store'])->middleware('permission:products.update');
        Route::patch('/categories/{category}', [Tenant\CategoryController::class, 'update'])->middleware('permission:products.update');
        Route::delete('/categories/{category}', [Tenant\CategoryController::class, 'destroy'])->middleware('permission:products.update');

        Route::get('/payments', [Tenant\PaymentController::class, 'index'])->middleware('permission:payments.view');

        Route::get('/payment-methods', [Tenant\CompanyPaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [Tenant\CompanyPaymentMethodController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('/payment-methods/{companyPaymentMethod}/active', [Tenant\CompanyPaymentMethodController::class, 'updateActive'])->middleware('permission:settings.update');
        Route::patch('/payment-methods/{companyPaymentMethod}', [Tenant\CompanyPaymentMethodController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('/payment-methods/{companyPaymentMethod}', [Tenant\CompanyPaymentMethodController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('/customers', [Tenant\CustomerController::class, 'index'])->middleware('permission:customers.view|invoices.create|delivery_notes.create|quotes.create|proformas.create');
        Route::post('/customers', [Tenant\CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::patch('/customers/{customer}/active', [Tenant\CustomerController::class, 'updateActive'])->middleware('permission:customers.update');
        Route::patch('/customers/{customer}', [Tenant\CustomerController::class, 'update'])->middleware('permission:customers.update');
        Route::delete('/customers/{customer}', [Tenant\CustomerController::class, 'destroy'])->middleware('permission:customers.delete');

        Route::get('/suppliers', [Tenant\SupplierController::class, 'index'])->middleware('permission:suppliers.view|stock.view');
        Route::post('/suppliers', [Tenant\SupplierController::class, 'store'])->middleware('permission:suppliers.create');
        Route::patch('/suppliers/{supplier}/active', [Tenant\SupplierController::class, 'updateActive'])->middleware('permission:suppliers.update');
        Route::patch('/suppliers/{supplier}', [Tenant\SupplierController::class, 'update'])->middleware('permission:suppliers.update');
        Route::delete('/suppliers/{supplier}', [Tenant\SupplierController::class, 'destroy'])->middleware('permission:suppliers.delete');

        Route::get('/sales/preview', [Tenant\SaleController::class, 'preview']);
        Route::get('/sales', [Tenant\SaleController::class, 'index']);
        Route::post('/sales', [Tenant\SaleController::class, 'store']);
        Route::get('/sales/{sale}', [Tenant\SaleController::class, 'show']);
        Route::patch('/sales/{sale}', [Tenant\SaleController::class, 'update']);
        Route::post('/sales/{sale}/convert', [Tenant\SaleController::class, 'convert']);
        Route::patch('/sales/{sale}/payment', [Tenant\SaleController::class, 'updatePayment']);
        Route::post('/sales/{sale}/settle', [Tenant\SaleController::class, 'settle']);
        Route::post('/sales/{sale}/invoice', [Tenant\SaleController::class, 'invoiceAlbaran']);
        Route::post('/sales/{sale}/settlements/{settlement}/invoice', [Tenant\SaleController::class, 'invoiceSettlement']);
        Route::post('/sales/{sale}/returns', [Tenant\SaleController::class, 'returnPieces']);
        Route::delete('/sales/{sale}/returns/{return}', [Tenant\SaleController::class, 'cancelReturn']);
        Route::patch('/sales/{sale}/settlements/{settlement}', [Tenant\SaleController::class, 'updateSettlement']);
        Route::post('/sales/{sale}/void', [Tenant\SaleController::class, 'void']);

        Route::get('/product-images', [Tenant\ProductImageController::class, 'index'])->middleware('permission:product_images.view|products.view|invoices.create|delivery_notes.create|quotes.create|proformas.create');
        Route::post('/product-images', [Tenant\ProductImageController::class, 'store'])->middleware('permission:product_images.create');
        Route::get('/product-images/{productImage}/file', [Tenant\ProductImageController::class, 'file'])->middleware('permission:product_images.view|products.view|invoices.create|delivery_notes.create|quotes.create|proformas.create');
        Route::post('/product-images/{productImage}/replace', [Tenant\ProductImageController::class, 'replace'])->middleware('permission:product_images.update');
        Route::patch('/product-images/{productImage}', [Tenant\ProductImageController::class, 'update'])->middleware('permission:product_images.update');
        Route::delete('/product-images/{productImage}', [Tenant\ProductImageController::class, 'destroy'])->middleware('permission:product_images.delete');

        Route::get('/products', [Tenant\ProductController::class, 'index'])->middleware('permission:products.view|stock.view|invoices.create|delivery_notes.create|quotes.create|proformas.create');
        Route::post('/products', [Tenant\ProductController::class, 'store'])->middleware('permission:products.create');
        Route::post('/products/barcode', [Tenant\ProductController::class, 'generateBarcode'])->middleware('permission:products.create');
        Route::post('/products/import', Tenant\ProductImportController::class)->middleware('permission:products.create');
        Route::get('/products/{product}', [Tenant\ProductController::class, 'show'])->middleware('permission:products.view|stock.view|invoices.create|delivery_notes.create|quotes.create|proformas.create');
        Route::patch('/products/{product}', [Tenant\ProductController::class, 'update'])->middleware('permission:products.update');
        Route::delete('/products/{product}', [Tenant\ProductController::class, 'destroy'])->middleware('permission:products.delete');

        Route::get('/print-templates', [Tenant\PrintTemplateController::class, 'index'])->middleware('permission:printables.view|invoices.view|delivery_notes.view|quotes.view|proformas.view');
        Route::put('/print-templates/{type}', [Tenant\PrintTemplateController::class, 'update'])
            ->middleware('permission:printables.update')
            ->whereIn('type', ['factura', 'albaran', 'quotation', 'proforma']);
        Route::post('/print-templates/{type}/reset', [Tenant\PrintTemplateController::class, 'reset'])
            ->middleware('permission:printables.update')
            ->whereIn('type', ['factura', 'albaran', 'quotation', 'proforma']);
        Route::post('/print-templates/{type}/copy', [Tenant\PrintTemplateController::class, 'copy'])
            ->middleware('permission:printables.update')
            ->whereIn('type', ['factura', 'albaran', 'quotation', 'proforma']);

        Route::patch('/company/locale', [Tenant\CompanySettingsController::class, 'updateLocale'])->middleware('role:business_admin');
        Route::patch('/company/document-locale', [Tenant\CompanySettingsController::class, 'updateDocumentLocale'])->middleware('role:business_admin');
        Route::get('/company', [Tenant\CompanySettingsController::class, 'show']);
        Route::patch('/company', [Tenant\CompanySettingsController::class, 'update'])->middleware('permission:settings.update');
        Route::patch('/password', [Tenant\PasswordController::class, 'update']);

        Route::get('/company/logo', [Tenant\CompanyLogoController::class, 'file']);
        Route::post('/company/logo', [Tenant\CompanyLogoController::class, 'store'])->middleware('permission:settings.update');
        Route::delete('/company/logo', [Tenant\CompanyLogoController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('/team', [Tenant\TeamController::class, 'index']);
        Route::post('/team', [Tenant\TeamController::class, 'store']);
        Route::put('/team/roles/{role}', [Tenant\TeamController::class, 'saveRole']);
        Route::delete('/team/roles/{role}', [Tenant\TeamController::class, 'resetRole']);
        Route::patch('/team/{user}', [Tenant\TeamController::class, 'update']);
        Route::patch('/team/{user}/status', [Tenant\TeamController::class, 'updateStatus']);
        Route::post('/team/{user}/password', [Tenant\TeamController::class, 'resetPassword']);
        Route::delete('/team/{user}', [Tenant\TeamController::class, 'destroy']);

        Route::get('/whatsapp/settings', [Tenant\WhatsAppController::class, 'settings']);
        Route::patch('/whatsapp/settings', [Tenant\WhatsAppController::class, 'updateSettings'])->middleware('permission:settings.update');
    });
});

