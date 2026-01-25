## Анализ SELL сделок и открытых позиций

### Скриншот 1: SELL сделки для BTCUSDT

**Найдено 4 SELL сделки:**

1. **id 33** (самая новая):
   - Quantity: 0.00073462 BTC
   - Price: 89079.9 USDT
   - Status: FILLED
   - Created: 2026-01-25 04:37:52
   - Filled: 2026-01-25 04:41:43
   - parent_id: 2
   - ✅ Корректная сделка

2. **id 7**:
   - Quantity: 0.00010000 BTC
   - Price: 95368.4 USDT
   - Status: FILLED
   - Created: 2026-01-17 17:53:52
   - Filled: 2026-01-17 17:53:53
   - parent_id: 6
   - ✅ Корректная сделка

3. **id 5**:
   - Quantity: 0.00005235 BTC
   - Price: 0.00000000 USDT
   - Status: FAILED
   - Created: 2026-01-17 17:35:06
   - Filled: NULL
   - parent_id: NULL
   - ⚠️ Неудачная сделка (можно игнорировать)

4. **id 1** (ПРОБЛЕМНАЯ):
   - Quantity: 0.00010674 BTC
   - Price: **93514.10000000 USDT** ⚠️
   - Status: FILLED
   - Created: 2026-01-14 13:51:30
   - Filled: **2026-01-13 19:04:42** ⚠️ (РАНЬШЕ created_at!)
   - parent_id: 3
   - ❌ **КРИТИЧЕСКАЯ ОШИБКА ДАННЫХ**

### Скриншот 2: Сумма открытых BUY позиций

**Результат SQL запроса:**
- total_btc: **0.00005631 BTC**

Это сумма всех открытых BUY позиций (closed_at IS NULL).

### Проблема: SELL сделка id 1

**Что не так:**
1. **Цена 93514.1** - это та самая цена, которая появлялась в уведомлениях!
2. **filled_at (2026-01-13) РАНЬШЕ created_at (2026-01-14)** - логическая ошибка
3. Эта сделка создана 14 января, но заполнена 13 января - невозможно!

**Почему это проблема:**
- Эта SELL сделка обрабатывается в `SyncOrdersCommand` при каждом запуске
- Она закрывает новые BUY позиции, используя старую цену 93514.1
- Из-за неправильной даты `filled_at` она может неправильно обрабатываться

### Решение

**Вариант 1: Исправить данные в БД**

```sql
-- Исправить filled_at для SELL сделки id 1
UPDATE trades
SET filled_at = '2026-01-14 13:51:35'  -- Через несколько секунд после created_at
WHERE id = 1
  AND side = 'SELL'
  AND symbol = 'BTCUSDT';
```

**Вариант 2: Проверить, какие BUY позиции закрыты этой SELL сделкой**

```sql
-- Проверить BUY позиции, закрытые SELL id 1
SELECT id, quantity, price, created_at, closed_at, realized_pnl
FROM trades
WHERE parent_id = 3  -- parent_id SELL сделки id 1
  AND side = 'BUY'
  AND symbol = 'BTCUSDT';
```

**Вариант 3: Если SELL id 1 уже обработала все свои позиции, можно пометить её как обработанную**

Но лучше исправить `filled_at`, чтобы данные были корректными.

### Сравнение с балансом

**Открытые позиции в БД:**
- total_btc: 0.00005631 BTC

**Баланс на бирже:**
- BTC: 0.00067371 (балансы) или 0.00072996 (активы)

**Расхождение:**
- БД показывает меньше, чем на бирже
- Это может быть из-за:
  1. SELL сделки, которые еще не синхронизированы
  2. BUY позиции, которые были закрыты, но `closed_at` не обновлен
  3. Проблема с SELL id 1, которая неправильно обрабатывается

### Рекомендации

1. **Исправить filled_at для SELL id 1:**
   ```sql
   UPDATE trades
   SET filled_at = created_at + INTERVAL 5 SECOND
   WHERE id = 1 AND side = 'SELL';
   ```

2. **Проверить, какие BUY позиции связаны с SELL id 1:**
   ```sql
   SELECT * FROM trades WHERE parent_id = 3;
   ```

3. **Проверить все закрытые позиции:**
   ```sql
   SELECT id, quantity, price, created_at, closed_at, realized_pnl
   FROM trades
   WHERE symbol = 'BTCUSDT'
     AND side = 'BUY'
     AND closed_at IS NOT NULL
   ORDER BY closed_at DESC;
   ```

4. **Запустить синхронизацию:**
   ```bash
   php artisan orders:sync
   ```

### Вывод

- **SELL сделка id 1 имеет критическую ошибку данных** (filled_at раньше created_at)
- **Цена 93514.1 из этой сделки** использовалась в уведомлениях
- **Открытых позиций в БД: 0.00005631 BTC** (меньше, чем на бирже)
- **Нужно исправить данные SELL id 1** и проверить синхронизацию
