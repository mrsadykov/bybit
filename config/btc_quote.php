<?php

return [
    'enabled' => env('BTC_QUOTE_ENABLED', true),
    'exchange' => 'okx',
    'symbols_for_form' => ['SOLBTC', 'ETHBTC', 'BNBBTC'],

    // Алерт в Telegram при дневном убытке по всем BTC-quote ботам (сумма realized_pnl_btc за сегодня).
    // Например 0.001 — алерт, если дневной PnL <= -0.001 BTC. null — не слать.
    'alert_daily_loss_btc' => env('BTC_QUOTE_ALERT_DAILY_LOSS_BTC') !== null && env('BTC_QUOTE_ALERT_DAILY_LOSS_BTC') !== '' ? (float) env('BTC_QUOTE_ALERT_DAILY_LOSS_BTC') : null,
];
