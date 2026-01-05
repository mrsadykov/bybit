<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    protected $fillable = [
        'trading_bot_id',
        'side',
        'symbol',
        'price',
        'quantity',
        'status',
        'exchange_response'
    ];

    protected $casts = [
        'exchange_response' => 'array',
        'price' => 'decimal:8',
        'quantity' => 'decimal:8',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class);
    }
}
