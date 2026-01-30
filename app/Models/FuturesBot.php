<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuturesBot extends Model
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
        'position_size_usdt',
        'leverage',
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
        'position_size_usdt' => 'decimal:2',
        'leverage' => 'integer',
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

    public function futuresTrades(): HasMany
    {
        return $this->hasMany(FuturesTrade::class, 'futures_bot_id');
    }
}
