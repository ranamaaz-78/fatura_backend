<?php

namespace App\Providers;

use App\Contracts\WhatsAppSender;
use App\Services\WhatsApp\CloudApiWhatsAppSender;
use App\Services\WhatsApp\LinkWhatsAppSender;
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
        //
    }
}
