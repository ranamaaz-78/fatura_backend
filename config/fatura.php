<?php

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'support' => [
        'email' => env('SUPPORT_EMAIL', 'info@ykdigitalsolutions.com'),
        'whatsapp' => env('SUPPORT_WHATSAPP', '+34678708084'),
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'link'),
    ],

    'subscriptions' => [
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 0),
        'reminder_days' => [7, 3, 1],
    ],

    'quotations' => [
        // A quotation can be edited and converted until the end of this many days after its date.
        'valid_days' => (int) env('QUOTATION_VALID_DAYS', 7),
    ],

    // Days start and end on the clock of the business, not of the server (which keeps UTC).
    'timezone' => env('BUSINESS_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    'phone' => [
        'default_country' => env('PHONE_DEFAULT_COUNTRY', 'ES'),
    ],

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
