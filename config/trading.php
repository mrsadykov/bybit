<?php

return [
    'real_trading' => env('REAL_TRADING', false),

    // Минимальная сумма сделки в USDT
    'min_notional_usdt' => 1,

    // защита от дурака
    'max_trades_per_bot' => 1,
];
