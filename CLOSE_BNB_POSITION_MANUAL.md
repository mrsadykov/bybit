# 🔧 Закрытие позиции BNBUSDT вручную

## 📊 Текущая ситуация

- **Trade #17 (BUY BNBUSDT)**: `closed_at=NULL` - НЕ ЗАКРЫТА
- **Trade #18 (SELL BNBUSDT)**: `parent_id=17` - связан с BUY
- **Баланс на бирже**: BNB = 3.55E-7 (почти нет)

Позиция была продана на бирже, но не закрыта в БД.

---

## ✅ Решение: Закрыть позицию вручную

### Команда для выполнения на проде:

```bash
php artisan tinker
```

### Код для выполнения в tinker:

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
    echo "Найдено:\n";
    echo "BUY #{$buy->id}: {$buy->quantity} @ \${$buy->price}\n";
    echo "SELL #{$sell->id}: {$sell->quantity} @ \${$sell->price}\n\n";
    
    // Рассчитать PnL
    $pnl = ($sell->price * $sell->quantity) 
         - ($buy->price * $buy->quantity) 
         - ($buy->fee ?? 0) 
         - ($sell->fee ?? 0);
    
    echo "PnL: " . number_format($pnl, 8) . " USDT\n\n";
    
    // Закрыть позицию
    $buy->update([
        'closed_at' => $sell->filled_at ?? now(),
        'realized_pnl' => $pnl,
    ]);
    
    echo "✅ Позиция #{$buy->id} закрыта!\n";
    echo "   closed_at: {$buy->closed_at}\n";
    echo "   realized_pnl: " . number_format($pnl, 8) . " USDT\n";
} else {
    if (!$buy) {
        echo "❌ BUY позиция не найдена\n";
    }
    if (!$sell) {
        echo "❌ SELL ордер не найден\n";
    }
}

exit
```

---

## 📋 Пошаговая инструкция

1. **Подключиться к серверу:**
   ```bash
   ssh root@your-server
   ```

2. **Перейти в директорию проекта:**
   ```bash
   cd /var/www/trading-bot
   ```

3. **Запустить tinker:**
   ```bash
   php artisan tinker
   ```

4. **Вставить код выше и нажать Enter**

5. **Проверить результат:**
   - Должно показать: `✅ Позиция #17 закрыта!`
   - В веб-интерфейсе позиция должна исчезнуть из списка открытых

---

## 🔍 Проверка после закрытия

```bash
# В tinker
\App\Models\Trade::find(17)->closed_at;  // Должно быть не NULL
\App\Models\Trade::find(17)->realized_pnl;  // Должен быть рассчитан
```

---

## 💡 Альтернатива: Исправить команду orders:sync

Если хотите исправить команду `orders:sync`:

1. **Проверить, существует ли файл на проде:**
   ```bash
   ls -la app/Console/Commands/SyncOrdersCommand.php
   ```

2. **Если файла нет - задеплоить изменения:**
   ```bash
   git pull
   composer dump-autoload
   php artisan config:clear
   ```

3. **Проверить команду:**
   ```bash
   php artisan list | grep sync
   ```

Но для быстрого решения лучше использовать tinker - это займет 30 секунд.
