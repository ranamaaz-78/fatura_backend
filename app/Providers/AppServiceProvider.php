<?php

namespace App\Providers;

use App\Contracts\WhatsAppSender;
use App\Services\WhatsApp\CloudApiWhatsAppSender;
use App\Services\WhatsApp\LinkWhatsAppSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppSender::class, function () {
            return match (config('fatura.whatsapp.driver')) {
                'cloud_api' => new CloudApiWhatsAppSender,
                default => new LinkWhatsAppSender,
            };
        });
    }

    public function boot(): void
    {
        // Debug mode puts stack traces, SQL and file paths into error answers. On a live server that must never
        // happen, so a forgotten APP_DEBUG=true is switched off here and reported in the log instead.
        if ($this->app->environment('production') && config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG=true was ignored because APP_ENV=production. Set APP_DEBUG=false in the .env.');
        }
    }
}
