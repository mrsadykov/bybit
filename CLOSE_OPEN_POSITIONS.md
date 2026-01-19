# 🔧 Закрытие открытых позиций в БД

## 📊 Текущая ситуация

**Открытые позиции в БД:**
- ETHUSDT: 0.00155300 @ $3,218.56 (Bot #2)
- SOLUSDT: 0.03739100 @ $133.72 (Bot #3)

**Реальный баланс на бирже:**
- ETH: 0.00000089 (очень мало)
- SOL: 0.0000006 (очень мало)

**Проблема:**
- Позиции были частично проданы
- Но в БД они не закрыты (`closed_at = NULL`)
- Бот думает, что есть открытые позиции
- `canBuy()` возвращает `FALSE` → бот не может покупать

---

## ✅ Решение: Закрыть позиции в БД

### Вариант 1: Закрыть все открытые позиции (рекомендую)

```bash
php artisan tinker
```

В tinker:
```php
// Закрыть все открытые BUY позиции для ETH и SOL
\App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->whereIn('symbol', ['ETHUSDT', 'SOLUSDT'])
    ->update([
        'closed_at' => now(),
        'realized_pnl' => 0  // PnL = 0, т.к. позиции были частично проданы
    ]);

// Проверить результат
\App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->whereIn('symbol', ['ETHUSDT', 'SOLUSDT'])
    ->count();
// Должно вернуть 0

exit
```

---

### Вариант 2: Закрыть позиции по конкретным ботам

```bash
php artisan tinker
```

В tinker:
```php
// Закрыть позиции для Bot #2 (ETHUSDT)
\App\Models\Trade::where('trading_bot_id', 2)
    ->where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->update([
        'closed_at' => now(),
        'realized_pnl' => 0
    ]);

// Закрыть позиции для Bot #3 (SOLUSDT)
\App\Models\Trade::where('trading_bot_id', 3)
    ->where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->update([
        'closed_at' => now(),
        'realized_pnl' => 0
    ]);

exit
```

---

### Вариант 3: Закрыть конкретные сделки по ID

Сначала найдите ID сделок:
```bash
php artisan tinker
```

В tinker:
```php
// Найти открытые позиции
\App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->whereIn('symbol', ['ETHUSDT', 'SOLUSDT'])
    ->get()
    ->each(function($t) {
        echo "Trade #{$t->id}: {$t->symbol} | qty={$t->quantity} | price=\${$t->price} | bot_id={$t->trading_bot_id}\n";
    });

// Закрыть по ID (замените ID на реальные)
\App\Models\Trade::whereIn('id', [ID1, ID2])
    ->update([
        'closed_at' => now(),
        'realized_pnl' => 0
    ]);

exit
```

---

## 🔍 Проверка после закрытия

### 1. Проверить, что позиции закрыты:

```bash
php artisan tinker
```

В tinker:
```php
// Должно вернуть 0
\App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->whereIn('symbol', ['ETHUSDT', 'SOLUSDT'])
    ->count();

exit
```

### 2. Проверить, что бот может покупать:

```bash
php artisan tinker
```

В tinker:
```php
\App\Models\TradingBot::whereIn('id', [2, 3])->get()->each(function($bot) {
    $pm = new \App\Services\Trading\PositionManager($bot);
    $pos = $pm->getNetPosition();
    $canBuy = $pm->canBuy();
    echo "Bot #{$bot->id} ({$bot->symbol}): netPosition={$pos}, canBuy=" . ($canBuy ? 'YES ✅' : 'NO ❌') . "\n";
});

exit
```

**Ожидаемый результат:**
```
Bot #2 (ETHUSDT): netPosition=0, canBuy=YES ✅
Bot #3 (SOLUSDT): netPosition=0, canBuy=YES ✅
```

### 3. Запустить бота для проверки:

```bash
php artisan bots:run
```

Бот должен показать:
- `canBuy() = true`
- При сигнале BUY → сможет покупать

---

## ⚠️ Важно

**Почему PnL = 0:**
- Позиции были частично проданы
- Реальный баланс очень маленький
- Невозможно точно рассчитать PnL
- Устанавливаем `realized_pnl = 0`

**После закрытия:**
- ✅ Позиции исчезнут из списка "Открытые позиции"
- ✅ Бот сможет покупать при сигнале BUY
- ✅ Маленькие остатки на бирже не будут мешать

---

## 📋 Быстрая команда (все в одном)

```bash
php artisan tinker
```

В tinker:
```php
// Закрыть все открытые позиции для ETH и SOL
$closed = \App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->whereIn('symbol', ['ETHUSDT', 'SOLUSDT'])
    ->update([
        'closed_at' => now(),
        'realized_pnl' => 0
    ]);

echo "Закрыто позиций: {$closed}\n";

// Проверить результат
\App\Models\TradingBot::whereIn('id', [2, 3])->get()->each(function($bot) {
    $pm = new \App\Services\Trading\PositionManager($bot);
    $pos = $pm->getNetPosition();
    $canBuy = $pm->canBuy();
    echo "Bot #{$bot->id} ({$bot->symbol}): netPosition={$pos}, canBuy=" . ($canBuy ? 'YES ✅' : 'NO ❌') . "\n";
});

exit
```

---

## ✅ После закрытия

1. ✅ Позиции исчезнут из списка "Открытые позиции"
2. ✅ Бот сможет покупать при сигнале BUY
3. ✅ Маленькие остатки на бирже не будут мешать
4. ✅ Можно создавать новые боты или торговать существующими
