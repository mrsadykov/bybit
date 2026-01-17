# 🔍 Команды для проверки ботов в БД

## Проверка активного бота:

```bash
php artisan tinker
```

```php
// Найти активного бота
$bot = \App\Models\TradingBot::where('is_active', true)->first();

if ($bot) {
    echo "Бот #{$bot->id}\n";
    echo "Символ: {$bot->symbol}\n";
    echo "Таймфрейм: {$bot->timeframe}\n";
    echo "Стратегия: {$bot->strategy}\n";
    echo "Размер позиции: {$bot->position_size} USDT\n";
    echo "Stop-Loss: " . ($bot->stop_loss_percent ?? 'не установлен') . "%\n";
    echo "Take-Profit: " . ($bot->take_profit_percent ?? 'не установлен') . "%\n";
    echo "Dry Run: " . ($bot->dry_run ? 'да' : 'нет') . "\n";
    echo "Активен: " . ($bot->is_active ? 'да' : 'нет') . "\n";
    echo "Последняя сделка: " . ($bot->last_trade_at ?? 'никогда') . "\n";
} else {
    echo "Активных ботов не найдено\n";
}

exit
```

## Проверка всех ботов:

```php
// В tinker
$bots = \App\Models\TradingBot::all();
foreach ($bots as $bot) {
    echo "Бот #{$bot->id}: {$bot->symbol}, active: " . ($bot->is_active ? 'да' : 'нет') . ", SL: " . ($bot->stop_loss_percent ?? '-') . ", TP: " . ($bot->take_profit_percent ?? '-') . "\n";
}
```

## SQL проверка:

```sql
SELECT 
    id, 
    symbol, 
    timeframe, 
    strategy, 
    position_size, 
    stop_loss_percent, 
    take_profit_percent, 
    is_active, 
    dry_run 
FROM trading_bots 
WHERE is_active = 1;
```
