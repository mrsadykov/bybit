<?php

return [
    'real_trading' => env('REAL_TRADING', false),

    // Минимальная сумма сделки в USDT
    'min_notional_usdt' => 1,

    // защита от дурака
    'max_trades_per_bot' => 1,

    // --- Снижение риска (п.1–4) ---
    // 1. Множитель размера позиции: 0.5 = торговать половинным размером по всем ботам (spot/futures/btc-quote)
    'position_size_multiplier' => (float) (env('TRADING_POSITION_SIZE_MULTIPLIER', 1)),

    // 2. Глобальный стоп-лосс (%): если задан — используется вместо значения в боте (ужесточение)
    'stop_loss_percent_override' => env('TRADING_STOP_LOSS_PERCENT_OVERRIDE') !== null && env('TRADING_STOP_LOSS_PERCENT_OVERRIDE') !== '' ? (float) env('TRADING_STOP_LOSS_PERCENT_OVERRIDE') : null,

    // Трейлинг-стоп: продажа при откате от максимума с момента входа (только спот)
    'trailing_stop_percent' => env('TRADING_TRAILING_STOP_PERCENT') !== null && env('TRADING_TRAILING_STOP_PERCENT') !== '' ? (float) env('TRADING_TRAILING_STOP_PERCENT') : null,
    // Активация трейлинг-стопа: начать отслеживать максимум только после роста цены на X% от входа (0 = с входа)
    'trailing_stop_activation_percent' => env('TRADING_TRAILING_STOP_ACTIVATION_PERCENT') !== null && env('TRADING_TRAILING_STOP_ACTIVATION_PERCENT') !== '' ? (float) env('TRADING_TRAILING_STOP_ACTIVATION_PERCENT') : 0,

    // 3. Реже торговать: консервативные RSI 35/65 (если true — подменяют пороги бота только для входа/выхода по сигналу)
    'conservative_rsi' => env('TRADING_CONSERVATIVE_RSI', false),
    // Минимальный интервал (минуты) между открытием новых позиций по одному боту
    'min_minutes_between_opens' => env('TRADING_MIN_MINUTES_BETWEEN_OPENS') !== null && env('TRADING_MIN_MINUTES_BETWEEN_OPENS') !== '' ? (int) env('TRADING_MIN_MINUTES_BETWEEN_OPENS') : null,

    // 4. Пауза новых открытий: при дневном убытке по типу ботов >= N USDT новые BUY не выставляются (SL/TP и закрытие по сигналу работают)
    'pause_new_opens_daily_loss_usdt' => env('TRADING_PAUSE_NEW_OPENS_DAILY_LOSS_USDT') !== null && env('TRADING_PAUSE_NEW_OPENS_DAILY_LOSS_USDT') !== '' ? (float) env('TRADING_PAUSE_NEW_OPENS_DAILY_LOSS_USDT') : null,

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

    // Фильтр тренда по длинной EMA: не открывать лонг, если цена ниже длинной EMA (только спот)
    'trend_filter_enabled' => filter_var(env('TRADING_TREND_FILTER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'trend_filter_ema_period' => env('TRADING_TREND_FILTER_EMA_PERIOD') !== null && env('TRADING_TREND_FILTER_EMA_PERIOD') !== '' ? (int) env('TRADING_TREND_FILTER_EMA_PERIOD') : 50,
    'trend_filter_tolerance_percent' => env('TRADING_TREND_FILTER_TOLERANCE_PERCENT') !== null && env('TRADING_TREND_FILTER_TOLERANCE_PERCENT') !== '' ? (float) env('TRADING_TREND_FILTER_TOLERANCE_PERCENT') : 0,

    // Фильтр по объёму: BUY только если объём последней свечи >= среднего за период (только спот)
    'volume_filter_enabled' => filter_var(env('TRADING_VOLUME_FILTER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'volume_filter_period' => env('TRADING_VOLUME_FILTER_PERIOD') !== null && env('TRADING_VOLUME_FILTER_PERIOD') !== '' ? (int) env('TRADING_VOLUME_FILTER_PERIOD') : 20,
    'volume_filter_min_ratio' => env('TRADING_VOLUME_FILTER_MIN_RATIO') !== null && env('TRADING_VOLUME_FILTER_MIN_RATIO') !== '' ? (float) env('TRADING_VOLUME_FILTER_MIN_RATIO') : 1.0,

    'bybit' => [
        'env' => env('BYBIT_ENV', 'testnet'), // testnet | production
    ],
];
