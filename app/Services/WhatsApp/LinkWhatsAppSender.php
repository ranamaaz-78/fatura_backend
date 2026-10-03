<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppSender;
use App\Support\Phone;
use App\Support\WhatsAppResult;

/**
 * Builds a wa.me click-to-chat URL. No message leaves the server.
 */
class LinkWhatsAppSender implements WhatsAppSender
{
    public function send(string $to, string $message): WhatsAppResult
    {
        $digits = Phone::waDigits($to);

        if ($digits === null) {
            return WhatsAppResult::failed(__('Invalid WhatsApp number.'));
        }

        return WhatsAppResult::link('https://wa.me/'.$digits.'?text='.rawurlencode($message));
    }
}
