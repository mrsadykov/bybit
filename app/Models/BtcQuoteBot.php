<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BtcQuoteBot extends Model
{
    protected $fillable = [
        'user_id',
        'exchange_account_id',
        'symbol',
        'timeframe',
        'strategy',
        'position_size_btc',
        'rsi_period',
        'ema_period',
        'rsi_buy_threshold',
        'rsi_sell_threshold',
        'stop_loss_percent',
        'take_profit_percent',
        'is_active',
        'dry_run',
        'last_trade_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dry_run' => 'boolean',
        'last_trade_at' => 'datetime',
        'position_size_btc' => 'decimal:8',
        'stop_loss_percent' => 'decimal:2',
        'take_profit_percent' => 'decimal:2',
        'rsi_buy_threshold' => 'decimal:2',
        'rsi_sell_threshold' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function btcQuoteTrades(): HasMany
    {
        return $this->hasMany(BtcQuoteTrade::class, 'btc_quote_bot_id');
    }

    /** Базовая валюта пары (SOL для SOLBTC) */
    public function getBaseCurrencyAttribute(): string
    {
        if (str_ends_with($this->symbol, 'BTC')) {
            return substr($this->symbol, 0, -3);
        }
        return $this->symbol;
    }
}
