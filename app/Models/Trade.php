<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    protected $fillable = [
        'trading_bot_id',
        'side',
        'symbol',
        'price',
        'quantity',
        'status', // PENDING, SENT, FILLED, PARTIALLY_FILLED, FAILED
        'exchange_response',
        'fee',
        'fee_currency',
        'order_id',
        'filled_at',
        'parent_id',
        'closed_at',
        'realized_pnl'
    ];

    protected $casts = [
        'exchange_response' => 'array',
        'price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'realized_pnl' => 'decimal:8',
        'filled_at' => 'datetime',
        'closed_at' => 'datetime',
        'fee' => 'decimal:8'
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class, 'trading_bot_id', 'id');
    }

    /**
     * У элемента Trade с SIDE BUY может быть несколько связанных записей с SIDE SELL
     *
     * @return HasMany
     */
    //  $buy->sells;
    public function sells(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * У элемента Trade с SIDE SELL обязательно есть связанная запись с SIDE BUY
     *
     * @return BelongsTo
     */
    // $sell->parent;
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
