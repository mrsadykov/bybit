# Как исправить сделки в БД вручную

## Шаг 1: Проверить, какие сделки нужно исправить

### Вариант A: Через SQL (phpMyAdmin, MySQL Workbench, или командная строка)

```sql
-- 1. Сначала посмотрим, какие сделки нужно исправить
SELECT 
    id,
    side,
    symbol,
    created_at,
    closed_at,
    realized_pnl,
    status,
    (SELECT COUNT(*) FROM trades t2 WHERE t2.parent_id = trades.id AND t2.side = 'SELL' AND t2.status = 'FILLED') as sell_count
FROM trades
WHERE side = 'BUY'
  AND closed_at = '2026-01-13 19:04:42'
ORDER BY id DESC;
```

Это покажет все BUY сделки с неправильным `closed_at`, у которых нет SELL сделок.

### Вариант B: Через Laravel Tinker

```bash
php artisan tinker
```

Затем в Tinker:

```php
// Проверить сделки, которые нужно исправить
$tradesToFix = \App\Models\Trade::where('side', 'BUY')
    ->where('closed_at', '2026-01-13 19:04:42')
    ->get()
    ->filter(function($trade) {
        // Проверяем, есть ли SELL сделка для этого BUY
        $hasSell = \App\Models\Trade::where('parent_id', $trade->id)
            ->where('side', 'SELL')
            ->where('status', 'FILLED')
            ->exists();
        return !$hasSell;
    });

echo "Найдено сделок для исправления: " . $tradesToFix->count() . "\n";
foreach ($tradesToFix as $trade) {
    echo "ID: {$trade->id}, Symbol: {$trade->symbol}, Created: {$trade->created_at}\n";
}
```

---

## Шаг 2: Исправить сделки

### Вариант A: Через SQL

```sql
-- Исправить все BUY сделки с неправильным closed_at, у которых нет SELL
UPDATE trades
SET closed_at = NULL, realized_pnl = NULL
WHERE side = 'BUY'
  AND closed_at = '2026-01-13 19:04:42'
  AND NOT EXISTS (
    SELECT 1 FROM trades t2
    WHERE t2.parent_id = trades.id
      AND t2.side = 'SELL'
      AND t2.status = 'FILLED'
  );
```

**Проверка после исправления:**
```sql
-- Проверить, что исправлено
SELECT COUNT(*) as fixed_count
FROM trades
WHERE side = 'BUY'
  AND closed_at = '2026-01-13 19:04:42';
-- Должно вернуть 0
```

### Вариант B: Через Laravel Tinker

```bash
php artisan tinker
```

Затем:

```php
// Исправить сделки
$fixed = 0;
$trades = \App\Models\Trade::where('side', 'BUY')
    ->where('closed_at', '2026-01-13 19:04:42')
    ->get();

foreach ($trades as $trade) {
    // Проверяем, есть ли SELL сделка
    $hasSell = \App\Models\Trade::where('parent_id', $trade->id)
        ->where('side', 'SELL')
        ->where('status', 'FILLED')
        ->exists();
    
    if (!$hasSell) {
        $trade->closed_at = null;
        $trade->realized_pnl = null;
        $trade->save();
        $fixed++;
        echo "Исправлена сделка #{$trade->id} ({$trade->symbol})\n";
    }
}

echo "Исправлено сделок: {$fixed}\n";
```

---

## Шаг 3: Проверить результат

### Проверить открытые позиции:

```sql
-- Проверить открытые BUY позиции
SELECT 
    id,
    symbol,
    quantity,
    price,
    created_at,
    closed_at,
    realized_pnl
FROM trades
WHERE side = 'BUY'
  AND status = 'FILLED'
  AND closed_at IS NULL
ORDER BY id DESC;
```

### Или через Tinker:

```php
// Проверить открытые позиции
$openPositions = \App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->get();

echo "Открытых позиций: " . $openPositions->count() . "\n";
foreach ($openPositions as $pos) {
    echo "ID: {$pos->id}, Symbol: {$pos->symbol}, Quantity: {$pos->quantity}, Price: {$pos->price}\n";
}
```

---

## Альтернатива: Исправить конкретные сделки по ID

Если вы знаете конкретные ID сделок, которые нужно исправить:

### Через SQL:

```sql
-- Исправить конкретные сделки (замените ID на реальные)
UPDATE trades
SET closed_at = NULL, realized_pnl = NULL
WHERE id IN (20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31);
```

### Через Tinker:

```php
// Исправить конкретные сделки
$ids = [20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31];
\App\Models\Trade::whereIn('id', $ids)
    ->where('side', 'BUY')
    ->update([
        'closed_at' => null,
        'realized_pnl' => null
    ]);
echo "Исправлено сделок: " . count($ids) . "\n";
```

---

## Полный скрипт для Tinker (копировать целиком)

```php
// 1. Проверить, что нужно исправить
echo "=== Проверка сделок ===\n";
$tradesToFix = \App\Models\Trade::where('side', 'BUY')
    ->where('closed_at', '2026-01-13 19:04:42')
    ->get()
    ->filter(function($trade) {
        $hasSell = \App\Models\Trade::where('parent_id', $trade->id)
            ->where('side', 'SELL')
            ->where('status', 'FILLED')
            ->exists();
        return !$hasSell;
    });

echo "Найдено сделок для исправления: " . $tradesToFix->count() . "\n\n";

// 2. Показать список
echo "=== Список сделок ===\n";
foreach ($tradesToFix as $trade) {
    echo "ID: {$trade->id}, Symbol: {$trade->symbol}, Created: {$trade->created_at}, PnL: {$trade->realized_pnl}\n";
}

// 3. Исправить
echo "\n=== Исправление ===\n";
$fixed = 0;
foreach ($tradesToFix as $trade) {
    $trade->closed_at = null;
    $trade->realized_pnl = null;
    $trade->save();
    $fixed++;
    echo "✓ Исправлена сделка #{$trade->id} ({$trade->symbol})\n";
}

echo "\n=== Результат ===\n";
echo "Исправлено сделок: {$fixed}\n";

// 4. Проверить открытые позиции
echo "\n=== Открытые позиции ===\n";
$openPositions = \App\Models\Trade::where('side', 'BUY')
    ->where('status', 'FILLED')
    ->whereNull('closed_at')
    ->get();
echo "Открытых позиций: " . $openPositions->count() . "\n";
```

---

## Важно!

1. **Сделайте backup БД перед исправлением:**
   ```bash
   mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Проверьте результат после исправления:**
   - Убедитесь, что `closed_at` стал `NULL` для незакрытых позиций
   - Убедитесь, что `realized_pnl` стал `NULL` для незакрытых позиций
   - Проверьте, что открытые позиции теперь видны

3. **После исправления:**
   - Запустите `php artisan bots:run` и проверьте, что бот видит открытые позиции
   - Проверьте, что `canBuy()` возвращает `false`, если есть открытые позиции

---

## Быстрый способ (если уверены)

Если вы уверены, что все BUY сделки с `closed_at = '2026-01-13 19:04:42'` нужно исправить:

```sql
-- Просто убрать closed_at и realized_pnl для всех BUY с этой датой
UPDATE trades
SET closed_at = NULL, realized_pnl = NULL
WHERE side = 'BUY'
  AND closed_at = '2026-01-13 19:04:42';
```

**Но лучше сначала проверить!**
