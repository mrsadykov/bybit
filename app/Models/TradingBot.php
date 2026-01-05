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
        'position_size',
        'is_active',
        'last_trade_at',
        'dry_run'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_trade_at' => 'datetime',
        // 8 знаков после запятой
        'position_size' => 'decimal:8',
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
