<?php

return [
    'title' => 'Trading Bots',
    'create_bot' => 'Create Bot',
    'edit_bot' => 'Edit Bot',
    'bot_details' => 'Bot Details',
    'bot_number' => 'Bot #:id',
    'back_to_list' => 'Back to List',
    
    // Status
    'active' => 'Active',
    'inactive' => 'Inactive',
    'dry_run' => 'DRY RUN',
    
    // Bot fields
    'symbol' => 'Trading Pair',
    'timeframe' => 'Timeframe',
    'strategy' => 'Strategy',
    'position_size' => 'Position Size',
    'exchange' => 'Exchange',
    'rsi_period' => 'RSI Period',
    'ema_period' => 'EMA Period',
    'rsi_buy_threshold' => 'RSI buy threshold (BUY)',
    'rsi_sell_threshold' => 'RSI sell threshold (SELL)',
    'rsi_sell_must_exceed_buy' => 'RSI SELL must be greater than RSI BUY.',
    'rsi_thresholds_help' => 'Empty = defaults (40/60 live, 45/55 backtest). E.g. 45/55.',
    'stop_loss' => 'Stop-Loss',
    'take_profit' => 'Take-Profit',
    'last_trade' => 'Last Trade',
    
    // Actions
    'view' => 'View',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'activate' => 'Activate',
    'deactivate' => 'Deactivate',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'create' => 'Create',
    'update' => 'Update',
    
    // Form labels
    'exchange_account' => 'Exchange Account',
    'trading_pair' => 'Trading Pair',
    'position_size_usdt' => 'Position Size (USDT)',
    'stop_loss_percent' => 'Stop-Loss (%)',
    'take_profit_percent' => 'Take-Profit (%)',
    'dry_run_mode' => 'Dry Run (test mode without real trading)',
    'is_active' => 'Active (bot will trade)',
    
    // Messages
    'bot_created' => 'Trading bot created successfully!',
    'bot_updated' => 'Trading bot updated successfully!',
    'bot_deleted' => 'Trading bot deleted successfully!',
    'bot_activated' => 'Bot activated!',
    'bot_deactivated' => 'Bot deactivated!',
    'no_bots' => 'You don\'t have any trading bots yet.',
    'create_first_bot' => 'Create your first bot',
    
    // Statistics
    'total_trades' => 'Total Trades',
    'filled_trades' => 'Filled',
    'total_pnl' => 'Total PnL',
    'win_rate' => 'Win Rate',
    'winning_trades' => 'Winning',
    'losing_trades' => 'Losing',
    'closed_positions' => 'Closed Positions',
    'open_positions' => 'Open Positions',
    'recent_trades' => 'Recent Trades',
    'daily_pnl' => 'Daily PnL (last 30 days)',
    'bot_settings' => 'Bot Settings',
    'status' => 'Status',
    
    // Trade table
    'date' => 'Date',
    'side' => 'Side',
    'quantity' => 'Quantity',
    'price' => 'Price',
    'pnl' => 'PnL',
    
    // Timeframes
    'timeframe_1' => '1 minute',
    'timeframe_3' => '3 minutes',
    'timeframe_5' => '5 minutes',
    'timeframe_15' => '15 minutes',
    'timeframe_30' => '30 minutes',
    'timeframe_60' => '1 hour',
    'timeframe_120' => '2 hours',
    'timeframe_240' => '4 hours',
    'timeframe_D' => 'Day',
    'timeframe_W' => 'Week',
    
    // Help text
    'symbol_format' => 'Format: BTCUSDT, ETHUSDT, SOLUSDT, etc.',
    'min_position_size' => 'Minimum size: 1 USDT',
    'stop_loss_help' => 'Sell when price drops by specified percentage',
    'take_profit_help' => 'Sell when price rises by specified percentage',

    // Risk limits (priority 2)
    'max_daily_loss_usdt' => 'Max daily loss (USDT)',
    'max_daily_loss_placeholder' => 'Empty = no limit',
    'max_daily_loss_help' => 'When daily loss reaches this, trading for this bot is paused until the next day.',
    'max_drawdown_percent' => 'Max drawdown (%)',
    'max_drawdown_placeholder' => 'Empty = no limit',
    'max_drawdown_help' => 'When drawdown from cumulative PnL peak exceeds this, trading for this bot is paused (clear manually or change limit).',

    // MACD (priority 3)
    'use_macd_filter' => 'MACD filter (RSI+EMA+MACD)',
    'use_macd_filter_help' => 'BUY only when MACD histogram ≥ 0, SELL only when histogram ≤ 0. Fewer false signals.',
];
