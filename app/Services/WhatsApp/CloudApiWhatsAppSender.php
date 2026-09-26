<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppSender;
use App\Support\WhatsAppResult;
use RuntimeException;

/**
 * Placeholder for the Meta Cloud API driver. Wired in a later module.
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function send(string $to, string $message): WhatsAppResult
    {
        throw new RuntimeException('The WhatsApp cloud_api driver is not implemented yet. Use WHATSAPP_DRIVER=link.');
    }
}
