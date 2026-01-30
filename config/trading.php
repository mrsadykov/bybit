<?php

return [
    'real_trading' => env('REAL_TRADING', false),

    // Минимальная сумма сделки в USDT
    'min_notional_usdt' => 1,

    // защита от дурака
    'max_trades_per_bot' => 1,

    // Алерты в Telegram (опционально): при достижении лимитов отправляется уведомление
    'alert_daily_loss_usdt' => env('TRADING_ALERT_DAILY_LOSS_USDT', null), // например 10 — алерт при дневном убытке >= 10 USDT
    'alert_losing_streak_count' => env('TRADING_ALERT_LOSING_STREAK', null), // например 3 — алерт при 3+ убыточных сделках подряд
    'alert_target_profit_usdt' => env('TRADING_ALERT_TARGET_PROFIT_USDT', null), // например 50 — алерт при достижении суммарной прибыли >= 50 USDT

    // Лимит открытых позиций (глобально по всем ботам пользователя): при достижении новые BUY не выставляются
    'max_open_positions_total' => env('TRADING_MAX_OPEN_POSITIONS_TOTAL', null), // например 5 — не более 5 открытых позиций суммарно

    // Допуск цены относительно EMA для стратегии RSI+EMA (в процентах)
    'ema_tolerance_percent' => (float) (env('TRADING_EMA_TOLERANCE_PERCENT', 1)), // BUY: цена >= EMA*(1 - X%). 1 = консервативно, 2–3 = больше входов
    'ema_tolerance_deep_percent' => env('TRADING_EMA_TOLERANCE_DEEP_PERCENT', null), // при RSI < rsi_deep_oversold использовать этот допуск для BUY (например 3)
    'rsi_deep_oversold' => env('TRADING_RSI_DEEP_OVERSOLD', null), // порог «глубокой перепроданности» (например 25). null = отключено

    'bybit' => [
        'env' => env('BYBIT_ENV', 'testnet'), // testnet | production
    ],
];
