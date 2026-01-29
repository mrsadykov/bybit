<?php

return [
    'title' => 'Торговые боты',
    'create_bot' => 'Создать бота',
    'edit_bot' => 'Редактировать бота',
    'bot_details' => 'Детали бота',
    'bot_number' => 'Бот #:id',
    'back_to_list' => 'Назад к списку',
    
    // Status
    'active' => 'Активен',
    'inactive' => 'Неактивен',
    'dry_run' => 'DRY RUN',
    
    // Bot fields
    'symbol' => 'Торговая пара',
    'timeframe' => 'Таймфрейм',
    'strategy' => 'Стратегия',
    'position_size' => 'Размер позиции',
    'exchange' => 'Биржа',
    'rsi_period' => 'RSI Период',
    'ema_period' => 'EMA Период',
    'rsi_buy_threshold' => 'RSI порог покупки (BUY)',
    'rsi_sell_threshold' => 'RSI порог продажи (SELL)',
    'rsi_sell_must_exceed_buy' => 'RSI SELL должен быть больше RSI BUY.',
    'rsi_thresholds_help' => 'Пусто = по умолчанию (40/60 в реале, 45/55 в бэктесте). Например 45/55.',
    'stop_loss' => 'Stop-Loss',
    'take_profit' => 'Take-Profit',
    'last_trade' => 'Последняя сделка',
    
    // Actions
    'view' => 'Просмотр',
    'edit' => 'Редактировать',
    'delete' => 'Удалить',
    'activate' => 'Запустить',
    'deactivate' => 'Остановить',
    'save' => 'Сохранить',
    'cancel' => 'Отмена',
    'create' => 'Создать',
    'update' => 'Обновить',
    
    // Form labels
    'exchange_account' => 'Биржевой аккаунт',
    'trading_pair' => 'Торговая пара',
    'position_size_usdt' => 'Размер позиции (USDT)',
    'stop_loss_percent' => 'Stop-Loss (%)',
    'take_profit_percent' => 'Take-Profit (%)',
    'dry_run_mode' => 'Dry Run (тестовый режим без реальной торговли)',
    'is_active' => 'Активен (бот будет торговать)',
    
    // Messages
    'bot_created' => 'Торговый бот успешно создан!',
    'bot_updated' => 'Торговый бот успешно обновлен!',
    'bot_deleted' => 'Торговый бот успешно удален!',
    'bot_activated' => 'Бот активирован!',
    'bot_deactivated' => 'Бот деактивирован!',
    'no_bots' => 'У вас пока нет торговых ботов.',
    'create_first_bot' => 'Создать первого бота',
    
    // Statistics
    'total_trades' => 'Всего сделок',
    'filled_trades' => 'Выполнено',
    'total_pnl' => 'Общий PnL',
    'win_rate' => 'Win Rate',
    'winning_trades' => 'Прибыльных',
    'losing_trades' => 'Убыточных',
    'closed_positions' => 'Закрытых позиций',
    'open_positions' => 'Открытых позиций',
    'recent_trades' => 'Последние сделки',
    'daily_pnl' => 'PnL по дням (последние 30 дней)',
    'bot_settings' => 'Настройки бота',
    'status' => 'Статус',
    
    // Trade table
    'date' => 'Дата',
    'side' => 'Сторона',
    'quantity' => 'Количество',
    'price' => 'Цена',
    'pnl' => 'PnL',
    
    // Timeframes
    'timeframe_1' => '1 минута',
    'timeframe_3' => '3 минуты',
    'timeframe_5' => '5 минут',
    'timeframe_15' => '15 минут',
    'timeframe_30' => '30 минут',
    'timeframe_60' => '1 час',
    'timeframe_120' => '2 часа',
    'timeframe_240' => '4 часа',
    'timeframe_D' => 'День',
    'timeframe_W' => 'Неделя',
    
    // Help text
    'symbol_format' => 'Формат: BTCUSDT, ETHUSDT, SOLUSDT и т.д.',
    'min_position_size' => 'Минимальный размер: 1 USDT',
    'stop_loss_help' => 'Продать при падении на указанный процент',
    'take_profit_help' => 'Продать при росте на указанный процент',
];
