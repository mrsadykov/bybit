<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotStatistics extends Model
{
    protected $table = 'bot_statistics';

    protected $fillable = [
        'trading_bot_id',
        'analysis_date',
        'days_period',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'win_rate',
        'total_pnl',
        'avg_pnl',
        'avg_win',
        'avg_loss',
        'profit_factor',
        'max_drawdown',
        'best_trade',
        'worst_trade',
        'avg_hold_time_hours',
        'trades_per_day',
    ];

    protected $casts = [
        'analysis_date' => 'date',
        'days_period' => 'integer',
        'total_trades' => 'integer',
        'winning_trades' => 'integer',
        'losing_trades' => 'integer',
        'win_rate' => 'decimal:2',
        'total_pnl' => 'decimal:8',
        'avg_pnl' => 'decimal:8',
        'avg_win' => 'decimal:8',
        'avg_loss' => 'decimal:8',
        'profit_factor' => 'decimal:2',
        'max_drawdown' => 'decimal:8',
        'best_trade' => 'decimal:8',
        'worst_trade' => 'decimal:8',
        'avg_hold_time_hours' => 'decimal:2',
        'trades_per_day' => 'decimal:2',
    ];

    public function tradingBot(): BelongsTo
    {
        return $this->belongsTo(TradingBot::class);
    }
}
