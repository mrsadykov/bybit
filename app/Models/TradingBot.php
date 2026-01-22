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
        'position_size',
        'stop_loss_percent',
        'take_profit_percent',
        'is_active',
        'last_trade_at',
        'dry_run',
        //'is_testnet'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_trade_at' => 'datetime',
        // 8 знаков после запятой
        'position_size' => 'decimal:8',
        'stop_loss_percent' => 'decimal:2',
        'take_profit_percent' => 'decimal:2',
        'dry_run' => 'boolean'
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
