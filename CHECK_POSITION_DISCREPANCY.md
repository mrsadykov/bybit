# Проверка расхождения позиции

## Проблема

Бот показывает позицию `5.619E-5 BTC`, но в БД нет открытых BUY позиций для BTCUSDT.

## Диагностика

### Шаг 1: Проверить все BUY сделки BTCUSDT (включая закрытые)

```sql
SELECT 
    id,
    quantity,
    price,
    created_at,
    closed_at,
    realized_pnl,
    status
FROM trades
WHERE side = 'BUY'
  AND symbol = 'BTCUSDT'
  AND status = 'FILLED'
ORDER BY id DESC
LIMIT 20;
```

### Шаг 2: Проверить все SELL сделки BTCUSDT

```sql
SELECT 
    id,
    parent_id,
    quantity,
    price,
    created_at,
    status
FROM trades
WHERE side = 'SELL'
  AND symbol = 'BTCUSDT'
ORDER BY id DESC
LIMIT 10;
```

### Шаг 3: Проверить баланс на бирже

```bash
php artisan balance:check BTC --exchange=okx
```

Сравните с позицией в БД.

### Шаг 4: Проверить частично закрытые позиции

Возможно, SELL сделка закрыла не все количество BUY позиции:

```sql
-- Проверить BUY позиции, которые были частично закрыты
SELECT 
    t1.id as buy_id,
    t1.quantity as buy_quantity,
    t1.closed_at,
    COALESCE(SUM(t2.quantity), 0) as sold_quantity,
    (t1.quantity - COALESCE(SUM(t2.quantity), 0)) as remaining_quantity
FROM trades t1
LEFT JOIN trades t2 ON t2.parent_id = t1.id AND t2.side = 'SELL' AND t2.status = 'FILLED'
WHERE t1.side = 'BUY'
  AND t1.symbol = 'BTCUSDT'
  AND t1.status = 'FILLED'
GROUP BY t1.id, t1.quantity, t1.closed_at
HAVING remaining_quantity > 0
ORDER BY t1.id DESC;
```

---

## Возможные причины

### Причина 1: Остаток после частичной продажи

SELL сделка #33 продала 0.00073462 BTC, но это могло быть меньше, чем сумма всех открытых позиций. В результате остался небольшой остаток.

**Решение:** Проверить, все ли BUY позиции были полностью закрыты.

### Причина 2: Округление

При расчете количества могло произойти округление, и остался небольшой остаток.

**Решение:** Проверить точность расчетов.

### Причина 3: Ошибка в синхронизации

Возможно, при синхронизации не все позиции были правильно закрыты.

**Решение:** Пересчитать позиции вручную.

---

## Решения

### Решение 1: Проверить баланс и синхронизировать

```bash
# Проверить баланс
php artisan balance:check BTC --exchange=okx

# Если баланс = 0, но позиция в БД > 0, нужно закрыть позиции
```

### Решение 2: Закрыть маленькие остатки

Если остаток очень маленький (< 0.0001 BTC), можно закрыть его вручную:

```sql
-- Найти все BUY позиции с маленькими остатками
SELECT 
    t1.id,
    t1.quantity as buy_quantity,
    COALESCE(SUM(t2.quantity), 0) as sold_quantity,
    (t1.quantity - COALESCE(SUM(t2.quantity), 0)) as remaining
FROM trades t1
LEFT JOIN trades t2 ON t2.parent_id = t1.id AND t2.side = 'SELL' AND t2.status = 'FILLED'
WHERE t1.side = 'BUY'
  AND t1.symbol = 'BTCUSDT'
  AND t1.status = 'FILLED'
  AND t1.closed_at IS NULL
GROUP BY t1.id, t1.quantity
HAVING remaining > 0 AND remaining < 0.0001;
```

### Решение 3: Использовать команду синхронизации позиций

```bash
php artisan position:sync-from-balance --bot=1 --force
```

Это синхронизирует позиции с балансом на бирже.

---

## Проверка

После диагностики проверьте:

1. **Баланс на бирже:**
   ```bash
   php artisan balance:check BTC --exchange=okx
   ```

2. **Позиция в БД:**
   ```sql
   SELECT SUM(quantity) as total_position
   FROM trades
   WHERE side = 'BUY'
     AND symbol = 'BTCUSDT'
     AND status = 'FILLED'
     AND closed_at IS NULL;
   ```

3. **Сравнить:**
   - Если баланс = 0, но позиция в БД > 0 → нужно закрыть позиции
   - Если баланс > 0, но позиция в БД = 0 → нужно создать синтетическую BUY позицию

---

## Быстрое решение

Если остаток очень маленький (< 0.0001 BTC), можно просто закрыть все оставшиеся позиции:

```sql
-- Закрыть все маленькие остатки
UPDATE trades
SET closed_at = NOW(), realized_pnl = 0
WHERE side = 'BUY'
  AND symbol = 'BTCUSDT'
  AND status = 'FILLED'
  AND closed_at IS NULL
  AND quantity < 0.0001;
```

Но сначала проверьте баланс на бирже!
