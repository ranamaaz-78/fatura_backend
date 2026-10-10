<?php

/*
 * What a team member of a company may do. The owner can do everything and is not listed here.
 *
 * "modules" says which actions exist for each area; "presets" are the starting points the owner picks from
 * when adding a member (each can be ticked and unticked before saving, and is stored per member).
 * Only what can really be done is listed. A document cannot be edited once it is issued, so invoices, delivery
 * notes and proformas have no "update": they have "pay" (record or change a payment, return pieces) and, for the
 * first two, "void". Only a quotation can still be edited. Converting a document counts as "create" on the
 * document it becomes.
 */

$modules = [
    'dashboard' => ['view'],
    'invoices' => ['view', 'create', 'pay', 'void'],
    'delivery_notes' => ['view', 'create', 'pay', 'void'],
    'quotes' => ['view', 'create', 'update'],
    'proformas' => ['view', 'create', 'pay'],
    'payments' => ['view'],
    'customers' => ['view', 'create', 'update', 'delete'],
    'suppliers' => ['view', 'create', 'update', 'delete'],
    'products' => ['view', 'create', 'update', 'delete'],
    'product_images' => ['view', 'create', 'update', 'delete'],
    'stock' => ['view'],
    'reports' => ['view'],
    'printables' => ['view', 'update'],
    'settings' => ['view', 'update'],
];

$every = [];
foreach ($modules as $module => $actions) {
    foreach ($actions as $action) {
        $every[] = "{$module}.{$action}";
    }
}

return [
    'modules' => $modules,

    'presets' => [
        // Everything operational, and the settings to look at but not to change.
        'manager' => array_values(array_diff($every, ['settings.update'])),

        // Works the counter: sells, looks up customers and stock, and sees what was paid.
        'cashier' => [
            'dashboard.view',
            'invoices.view', 'invoices.create', 'invoices.pay',
            'delivery_notes.view', 'delivery_notes.create', 'delivery_notes.pay',
            'quotes.view', 'quotes.create',
            'proformas.view',
            'payments.view',
            'customers.view', 'customers.create', 'customers.update',
            'products.view',
        ],

        // Reads the books: documents, customers, stock and reports; records payments; changes nothing else.
        'accountant' => [
            'dashboard.view',
            'invoices.view', 'invoices.pay',
            'delivery_notes.view', 'delivery_notes.pay',
            'quotes.view',
            'proformas.view', 'proformas.pay',
            'payments.view',
            'customers.view', 'suppliers.view', 'products.view', 'stock.view',
            'reports.view',
            'printables.view',
        ],
    ],

    // The area a document type belongs to.
    'documents' => [
        'factura' => 'invoices',
        'abono' => 'invoices',
        'albaran' => 'delivery_notes',
        'quotation' => 'quotes',
        'proforma' => 'proformas',
    ],
];
