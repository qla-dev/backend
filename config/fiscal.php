<?php

// Smart POS fiscal bridge. Same contract as qla-dev/invoice-maker: a separate Laravel
// worker exposes `php artisan fiscal:*` commands that talk to the Datecs device.
// The worker is not part of this repository; see docs/agents/smart-pos-fiscal-driver.md.
return [
    'php_bin' => env('FISCAL_PHP_BIN', 'php'),
    'worker_dir' => env('FISCAL_WORKER_DIR'),
    'timeout' => (int) env('FISCAL_TIMEOUT', 90),
    'operator' => env('FISCAL_OPERATOR', '1'),
    // VAT rate (percent, as stored on invoice items) => fiscal tax label programmed in the device.
    // Unknown rates are refused instead of being sent as tax-free.
    'tax_labels' => [
        '17.0000' => env('FISCAL_TAX_LABEL_17', 'E'),
        '0.0000' => env('FISCAL_TAX_LABEL_0', 'K'),
    ],
    'payment_methods' => ['cash', 'card', 'cheque', 'transfer', 'voucher'],
];
