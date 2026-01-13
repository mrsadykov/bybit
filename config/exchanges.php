<?php

return [
    'bybit' => [
        'base_url' => env('BYBIT_BASE_URL', 'https://api.bybit.com'),
        'recv_window' => 5000,
    ],

    'okx' => [
        'base_url' => env('OKX_BASE_URL', 'https://www.okx.com'),
    ],
];
