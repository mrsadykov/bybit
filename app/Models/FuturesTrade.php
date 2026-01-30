<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuturesTrade extends Model
{
    protected $fillable = [
        'futures_bot_id',
        'side',
        'symbol',
        'price',
        'quantity',
        'fee',
        'fee_currency',
        'status',
        'filled_at',
        'order_id',
        'exchange_response',
        'closed_at',
        'realized_pnl',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'fee' => 'decimal:8',
        'filled_at' => 'datetime',
        'closed_at' => 'datetime',
        'realized_pnl' => 'decimal:8',
        'exchange_response' => 'array',
    ];

    public function futuresBot(): BelongsTo
    {
        return $this->belongsTo(FuturesBot::class);
    }
}
