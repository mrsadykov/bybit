<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotDecisionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'bot_type',
        'bot_id',
        'symbol',
        'signal',
        'price',
        'rsi',
        'ema',
        'reason',
    ];

    protected $casts = [
        'price' => 'float',
        'rsi' => 'float',
        'ema' => 'float',
        'created_at' => 'datetime',
    ];

    /**
     * Записать решение бота в лог.
     *
     * @param  string   $botType  spot|futures|btc_quote
     * @param  int  $botId
     * @param  string  $symbol
     * @param  string  $signal  HOLD|BUY|SELL|SKIP
     * @param  float|null  $price
     * @param  float|null  $rsi
     * @param  float|null  $ema
     * @param  string|null  $reason
     */
    public static function log(
        string $botType,
        int $botId,
        string $symbol,
        string $signal,
        ?float $price = null,
        ?float $rsi = null,
        ?float $ema = null,
        ?string $reason = null
    ): void {
        try {
            self::create([
                'bot_type' => $botType,
                'bot_id' => $botId,
                'symbol' => $symbol,
                'signal' => $signal,
                'price' => $price,
                'rsi' => $rsi,
                'ema' => $ema,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            logger()->warning('BotDecisionLog::log failed', [
                'bot_type' => $botType,
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
