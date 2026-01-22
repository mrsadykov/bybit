# 🔧 Исправление проблемы с командой orders:sync

## ❌ Проблема

```bash
php artisan orders:sync
ERROR  There are no commands defined in the "orders" namespace.
```

## ✅ Решение

### Шаг 1: Очистить кэш на проде

```bash
cd /var/www/trading-bot

# Очистить все кэши
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Пересоздать автозагрузку
composer dump-autoload
```

### Шаг 2: Проверить, что команда появилась

```bash
php artisan list | grep sync
```

Должно показать:
```
orders:sync  Sync pending exchange orders
```

### Шаг 3: Запустить команду

```bash
php artisan orders:sync
```

---

## 🔍 Если команда все еще не найдена

### Проверка 1: Файл существует

```bash
ls -la app/Console/Commands/SyncOrdersCommand.php
```

### Проверка 2: Класс правильно определен

```bash
grep "class SyncOrdersCommand" app/Console/Commands/SyncOrdersCommand.php
grep "signature.*orders:sync" app/Console/Commands/SyncOrdersCommand.php
```

### Проверка 3: Автозагрузка

```bash
composer dump-autoload -v
```

---

## 💡 Альтернативное решение

Если команда все еще не работает, можно закрыть позицию вручную:

```bash
php artisan tinker
```

```php
// Найти BUY позицию BNBUSDT для бота #4
$buy = \App\Models\Trade::where('trading_bot_id', 4)
    ->where('symbol', 'BNBUSDT')
    ->where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->first();

// Найти SELL ордер
$sell = \App\Models\Trade::where('trading_bot_id', 4)
    ->where('symbol', 'BNBUSDT')
    ->where('side', 'SELL')
    ->where('status', 'FILLED')
    ->where('id', 18)
    ->first();

if ($buy && $sell) {
    // Рассчитать PnL
    $pnl = ($sell->price * $sell->quantity) 
         - ($buy->price * $buy->quantity) 
         - ($buy->fee ?? 0) 
         - ($sell->fee ?? 0);
    
    // Закрыть позицию
    $buy->update([
        'closed_at' => $sell->filled_at ?? now(),
        'realized_pnl' => $pnl,
    ]);
    
    echo "✅ Позиция закрыта! PnL: " . number_format($pnl, 8) . " USDT\n";
} else {
    echo "❌ Не найдены BUY или SELL ордера\n";
}

exit
```

---

## 🎯 Рекомендация

**Начните с очистки кэша** - это должно решить проблему в 99% случаев.
