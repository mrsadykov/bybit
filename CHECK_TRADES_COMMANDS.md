# 🔍 Команды для проверки сделок и PnL

## 📊 Проверка сделок на сервере:

### 1. Проверить все сделки:

```bash
php artisan tinker
```

```php
// Все сделки, отсортированные по дате (последние первые)
$trades = \App\Models\Trade::latest()->get();

echo "=== ВСЕ СДЕЛКИ ===\n";
foreach ($trades as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "Trade #{$trade->id}: {$trade->side}, Status: {$trade->status}\n";
    echo "  Symbol: {$trade->symbol}, Quantity: {$trade->quantity}, Price: {$trade->price}\n";
    echo "  Order ID: {$trade->order_id}, PnL: {$pnlSign}" . number_format($pnl, 8) . " USDT\n";
    echo "  Created: {$trade->created_at}, Filled: " . ($trade->filled_at ?? 'не исполнено') . "\n";
    if ($trade->closed_at) {
        echo "  Closed: {$trade->closed_at}\n";
    }
    echo "\n";
}

exit
```

---

### 2. Проверить последние сделки:

```bash
php artisan tinker
```

```php
// Последние 10 сделок
$trades = \App\Models\Trade::latest()->take(10)->get();

echo "=== ПОСЛЕДНИЕ 10 СДЕЛОК ===\n";
foreach ($trades as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "#{$trade->id} | {$trade->side} | {$trade->status} | Qty: {$trade->quantity} | Price: {$trade->price} | PnL: {$pnlSign}" . number_format($pnl, 8) . "\n";
}

exit
```

---

### 3. Проверить только BUY сделки:

```bash
php artisan tinker
```

```php
// Только BUY сделки
$buyTrades = \App\Models\Trade::where('side', 'BUY')->latest()->get();

echo "=== ПОКУПКИ (BUY) ===\n";
foreach ($buyTrades as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "Trade #{$trade->id}: Status: {$trade->status}, Quantity: {$trade->quantity}, Price: {$trade->price}\n";
    echo "  PnL: {$pnlSign}" . number_format($pnl, 8) . " USDT\n";
    echo "  Closed: " . ($trade->closed_at ? 'ДА' : 'НЕТ') . "\n";
    echo "\n";
}

exit
```

---

### 4. Проверить только SELL сделки:

```bash
php artisan tinker
```

```php
// Только SELL сделки
$sellTrades = \App\Models\Trade::where('side', 'SELL')->latest()->get();

echo "=== ПРОДАЖИ (SELL) ===\n";
foreach ($sellTrades as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "Trade #{$trade->id}: Status: {$trade->status}, Quantity: {$trade->quantity}, Price: {$trade->price}\n";
    echo "  Parent ID: {$trade->parent_id}, PnL: {$pnlSign}" . number_format($pnl, 8) . " USDT\n";
    echo "\n";
}

exit
```

---

### 5. Проверить открытые позиции (BUY без closed_at):

```bash
php artisan tinker
```

```php
// Открытые BUY позиции (не закрытые)
$openPositions = \App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->latest()
    ->get();

echo "=== ОТКРЫТЫЕ ПОЗИЦИИ ===\n";
if ($openPositions->isEmpty()) {
    echo "Нет открытых позиций\n";
} else {
    foreach ($openPositions as $trade) {
        echo "Trade #{$trade->id}: Quantity: {$trade->quantity} BTC, Price: {$trade->price} USDT\n";
        echo "  Buy Date: {$trade->filled_at}, Order ID: {$trade->order_id}\n";
        echo "\n";
    }
}

exit
```

---

### 6. Проверить закрытые позиции (BUY с closed_at):

```bash
php artisan tinker
```

```php
// Закрытые BUY позиции (с closed_at)
$closedPositions = \App\Models\Trade::where('side', 'BUY')
    ->whereNotNull('closed_at')
    ->latest()
    ->get();

echo "=== ЗАКРЫТЫЕ ПОЗИЦИИ ===\n";
$totalPnL = 0;
foreach ($closedPositions as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $totalPnL += $pnl;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "Trade #{$trade->id}: Quantity: {$trade->quantity} BTC, Buy Price: {$trade->price} USDT\n";
    echo "  PnL: {$pnlSign}" . number_format($pnl, 8) . " USDT\n";
    echo "  Buy Date: {$trade->filled_at}, Close Date: {$trade->closed_at}\n";
    echo "\n";
}

echo "=== ИТОГО ===\n";
echo "Всего закрытых позиций: " . $closedPositions->count() . "\n";
echo "Общий PnL: " . ($totalPnL >= 0 ? '+' : '') . number_format($totalPnL, 8) . " USDT\n";

exit
```

---

### 7. Проверить общую статистику:

```bash
php artisan tinker
```

```php
// Общая статистика
$allTrades = \App\Models\Trade::where('status', 'FILLED')->get();

$totalBuy = $allTrades->where('side', 'BUY')->count();
$totalSell = $allTrades->where('side', 'SELL')->count();
$openPositions = $allTrades->where('side', 'BUY')->whereNull('closed_at')->count();
$closedPositions = $allTrades->where('side', 'BUY')->whereNotNull('closed_at')->count();

$totalPnL = $allTrades->whereNotNull('realized_pnl')->sum('realized_pnl');
$winningTrades = $allTrades->where('side', 'BUY')->whereNotNull('realized_pnl')->where('realized_pnl', '>', 0)->count();
$losingTrades = $allTrades->where('side', 'BUY')->whereNotNull('realized_pnl')->where('realized_pnl', '<', 0)->count();

echo "=== ОБЩАЯ СТАТИСТИКА ===\n";
echo "Всего сделок BUY: {$totalBuy}\n";
echo "Всего сделок SELL: {$totalSell}\n";
echo "Открытых позиций: {$openPositions}\n";
echo "Закрытых позиций: {$closedPositions}\n";
echo "Общий PnL: " . ($totalPnL >= 0 ? '+' : '') . number_format($totalPnL, 8) . " USDT\n";
echo "Прибыльных сделок: {$winningTrades}\n";
echo "Убыточных сделок: {$losingTrades}\n";

if ($closedPositions > 0) {
    $winRate = ($winningTrades / $closedPositions) * 100;
    echo "Win Rate: " . number_format($winRate, 2) . "%\n";
}

exit
```

---

### 8. Проверить связь BUY и SELL (parent_id):

```bash
php artisan tinker
```

```php
// Проверить связь BUY и SELL сделок
$buyTrades = \App\Models\Trade::where('side', 'BUY')->latest()->get();

echo "=== СВЯЗЬ BUY И SELL ===\n";
foreach ($buyTrades as $buy) {
    echo "BUY #{$buy->id}: Quantity: {$buy->quantity}, Price: {$buy->price}\n";
    echo "  Status: {$buy->status}, Closed: " . ($buy->closed_at ? 'ДА' : 'НЕТ') . "\n";
    
    // Найти связанные SELL
    $sellTrades = \App\Models\Trade::where('side', 'SELL')
        ->where('parent_id', $buy->id)
        ->get();
    
    if ($sellTrades->isEmpty()) {
        echo "  SELL: нет связанных продаж\n";
    } else {
        foreach ($sellTrades as $sell) {
            echo "  SELL #{$sell->id}: Quantity: {$sell->quantity}, Price: {$sell->price}, Status: {$sell->status}\n";
        }
    }
    echo "\n";
}

exit
```

---

### 9. Проверить состояние бота:

```bash
php artisan tinker
```

```php
// Проверить активного бота
$bot = \App\Models\TradingBot::where('is_active', true)->first();

if ($bot) {
    echo "=== БОТ #{$bot->id} ===\n";
    echo "Символ: {$bot->symbol}\n";
    echo "Таймфрейм: {$bot->timeframe}\n";
    echo "Размер позиции: {$bot->position_size} USDT\n";
    echo "Stop-Loss: " . ($bot->stop_loss_percent ?? 'не установлен') . "%\n";
    echo "Take-Profit: " . ($bot->take_profit_percent ?? 'не установлен') . "%\n";
    echo "Dry Run: " . ($bot->dry_run ? 'ДА (тестовый режим)' : 'НЕТ (реальная торговля)') . "\n";
    echo "Активен: " . ($bot->is_active ? 'ДА' : 'НЕТ') . "\n";
    
    // Статистика по боту
    $botTrades = $bot->trades()->where('status', 'FILLED')->get();
    $openPositions = $bot->trades()->where('side', 'BUY')->where('status', 'FILLED')->whereNull('closed_at')->count();
    $closedPositions = $bot->trades()->where('side', 'BUY')->whereNotNull('closed_at')->count();
    $totalPnL = $bot->trades()->whereNotNull('realized_pnl')->sum('realized_pnl');
    
    echo "\n=== СТАТИСТИКА ПО БОТУ ===\n";
    echo "Всего сделок: " . $botTrades->count() . "\n";
    echo "Открытых позиций: {$openPositions}\n";
    echo "Закрытых позиций: {$closedPositions}\n";
    echo "Общий PnL: " . ($totalPnL >= 0 ? '+' : '') . number_format($totalPnL, 8) . " USDT\n";
} else {
    echo "Активных ботов не найдено\n";
}

exit
```

---

### 10. Проверить баланс на бирже:

```bash
php artisan tinker
```

```php
// Проверить баланс USDT и BTC
$account = \App\Models\ExchangeAccount::where('exchange', 'okx')->first();
$okx = \App\Services\Exchanges\ExchangeServiceFactory::create($account);

$usdtBalance = $okx->getBalance('USDT');
$btcBalance = $okx->getBalance('BTC');

echo "=== БАЛАНС НА БИРЖЕ ===\n";
echo "USDT: {$usdtBalance}\n";
echo "BTC: {$btcBalance}\n";

// Сравнить с позицией в БД
$positionManager = new \App\Services\Trading\PositionManager(
    \App\Models\TradingBot::where('is_active', true)->first()
);
$netPosition = $positionManager->getNetPosition();

echo "\n=== СРАВНЕНИЕ ===\n";
echo "Чистая позиция в БД: {$netPosition} BTC\n";
echo "Баланс BTC на бирже: {$btcBalance} BTC\n";

$difference = abs($netPosition - $btcBalance);
if ($difference < 0.0001) {
    echo "✅ Позиция совпадает (разница < 0.0001 BTC)\n";
} else {
    echo "⚠️ Разница: {$difference} BTC\n";
}

exit
```

---

## 📊 Анализ текущей ситуации:

### Что видно из `orders:sync`:

1. **Trade #3 (BUY)** - FILLED
2. **Trade #2 (BUY)** - FILLED
3. **Trade #1 (SELL)** - FILLED

### Логика закрытия позиций (FIFO):

Если SELL #1 продал позиции, то:
- SELL #1 может закрыть BUY #2 или BUY #3
- Или закрыть часть обоих BUY

### Проверка в БД:

Нужно проверить:
- `parent_id` SELL #1 - к какому BUY привязан
- `closed_at` BUY сделок - какие закрыты
- `realized_pnl` - рассчитан ли PnL

---

## 🎯 Рекомендуемая последовательность проверки:

1. ✅ Проверить все сделки (команда 1)
2. ✅ Проверить открытые позиции (команда 5)
3. ✅ Проверить закрытые позиции (команда 6)
4. ✅ Проверить общую статистику (команда 7)
5. ✅ Проверить связь BUY и SELL (команда 8)
6. ✅ Проверить состояние бота (команда 9)
7. ✅ Проверить баланс на бирже (команда 10)

---

## 💡 Быстрая проверка (все в одном):

```bash
php artisan tinker
```

```php
// Быстрая проверка всех сделок
$trades = \App\Models\Trade::latest()->get();

echo "=== ВСЕ СДЕЛКИ ===\n";
foreach ($trades as $trade) {
    $pnl = $trade->realized_pnl ?? 0;
    $pnlSign = $pnl >= 0 ? '+' : '';
    echo "#{$trade->id} | {$trade->side} | {$trade->status} | Qty: {$trade->quantity} | PnL: {$pnlSign}" . number_format($pnl, 8) . " | ";
    if ($trade->parent_id) {
        echo "Parent: #{$trade->parent_id} | ";
    }
    if ($trade->closed_at) {
        echo "Closed: {$trade->closed_at}";
    } else {
        echo "Open";
    }
    echo "\n";
}

// Проверить состояние бота
$bot = \App\Models\TradingBot::where('is_active', true)->first();
echo "\n=== БОТ ===\n";
echo "Dry Run: " . ($bot->dry_run ? 'ДА' : 'НЕТ') . "\n";
echo "Размер позиции: {$bot->position_size} USDT\n";

exit
```

---

## 📚 Дополнительная информация:

- `LOGS_LOCATION.md` - Где находятся логи
- `CHECK_BOT_COMMANDS.md` - Команды для проверки ботов
- `PRE_REAL_TRADING_CHECKLIST.md` - Чек-лист перед реальной торговлей
