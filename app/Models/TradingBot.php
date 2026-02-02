<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingBot extends Model
{
    protected $fillable = [
        'user_id',
        'exchange_account_id',
        'symbol',
        'timeframe',
        'strategy',
        'rsi_period',
        'ema_period',
        'rsi_buy_threshold',
        'rsi_sell_threshold',
        'position_size',
        'stop_loss_percent',
        'take_profit_percent',
        'max_daily_loss_usdt',
        'max_drawdown_percent',
        'risk_drawdown_reset_at',
        'max_losing_streak',
        'use_macd_filter',
        'is_active',
        'last_trade_at',
        'dry_run',
        //'is_testnet'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_trade_at' => 'datetime',
        'position_size' => 'decimal:8',
        'stop_loss_percent' => 'decimal:2',
        'take_profit_percent' => 'decimal:2',
        'max_daily_loss_usdt' => 'decimal:2',
        'max_drawdown_percent' => 'decimal:2',
        'risk_drawdown_reset_at' => 'datetime',
        'use_macd_filter' => 'boolean',
        'rsi_buy_threshold' => 'decimal:2',
        'rsi_sell_threshold' => 'decimal:2',
        'dry_run' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
