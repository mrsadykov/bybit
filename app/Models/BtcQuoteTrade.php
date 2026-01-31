<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BtcQuoteTrade extends Model
{
    protected $fillable = [
        'btc_quote_bot_id',
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
        'realized_pnl_btc',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'fee' => 'decimal:8',
        'filled_at' => 'datetime',
        'closed_at' => 'datetime',
        'realized_pnl_btc' => 'decimal:8',
        'exchange_response' => 'array',
    ];

    public function btcQuoteBot(): BelongsTo
    {
        return $this->belongsTo(BtcQuoteBot::class);
    }
}
