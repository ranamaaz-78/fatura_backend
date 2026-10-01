<?php

namespace App\Services;

/** What happened when an account-ready message went out: the email result and any WhatsApp link. */
final class AccountReadyDelivery
{
    public function __construct(
        public readonly bool $emailSent,
        public readonly ?string $emailError = null,
        public readonly ?string $whatsappUrl = null,
    ) {}
}
