<?php

namespace App\Contracts;

use App\Support\WhatsAppResult;

interface WhatsAppSender
{
    /**
     * @param  string  $to  Any phone format; implementations normalize to E.164.
     */
    public function send(string $to, string $message): WhatsAppResult;
}
