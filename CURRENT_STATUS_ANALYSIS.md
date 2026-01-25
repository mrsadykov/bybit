## Анализ актуальных данных на 2026-01-25 06:20

### Балансы на бирже (OKX)

**Из скриншота "Балансы аккаунтов":**
- BTC: **0.00072997**
- USDT: **67.34436658**
- Общий баланс: **137.11 USDT**

**Из скриншота "Активы":**
- BTC: **0.00072996**
- USDT: **67.34**
- BTC PnL: -¥0,11 (-0,17%) - небольшой убыток

### Состояние сделок в БД

**Из скриншота таблицы trades (46 записей):**

**Открытые BUY позиции (closed_at IS NULL):**
- Нужно проверить SQL запросом

**SELL сделки:**
- id 33: 0.00073462 BTC, price: 89079.9, FILLED, parent_id: 2
- id 7: 0.00010000 BTC, price: 95368.4, FILLED, parent_id: 6
- id 5: 0.00005235 BTC, FAILED
- id 1: 0.00010674 BTC, price: 93514.1, FILLED, parent_id: 3 (проблемная)

### Логи Telegram (06:20:03)

**BTCUSDT:**
- ⚠️ BUY пропущен: "Позиция уже открыта"
- Это означает, что `getNetPosition() > 0`

**BNBUSDT:**
- ⚠️ BUY пропущен: "Позиция уже открыта"
- Это означает, что `getNetPosition() > 0`

**ETHUSDT, SOLUSDT:**
- ⏸️ HOLD сигнал
- Нет открытых позиций

### Анализ расхождений

**Проблема:**
- На бирже: **0.00072997 BTC**
- В БД (из предыдущего запроса): **0.00005631 BTC**
- **Расхождение: ~0.00067366 BTC (~60 USDT)**

**Возможные причины:**
1. **BUY позиции закрыты, но `closed_at` не обновлен**
2. **SELL сделки не синхронизированы**
3. **Проблема с SELL id 1** (неправильная дата filled_at)
4. **Новые BUY позиции созданы, но еще не синхронизированы**

### Проверки

**1. Подсчитать точное количество открытых BUY позиций:**

```sql
SELECT SUM(quantity) as total_btc
FROM trades
WHERE trading_bot_id = 1
  AND symbol = 'BTCUSDT'
  AND side = 'BUY'
  AND status = 'FILLED'
  AND closed_at IS NULL;
```

**2. Проверить все BUY позиции BTCUSDT:**

```sql
SELECT id, quantity, price, created_at, closed_at, realized_pnl, status
FROM trades
WHERE trading_bot_id = 1
  AND symbol = 'BTCUSDT'
  AND side = 'BUY'
ORDER BY id DESC
LIMIT 20;
```

**3. Проверить закрытые позиции:**

```sql
SELECT id, quantity, price, created_at, closed_at, realized_pnl
FROM trades
WHERE trading_bot_id = 1
  AND symbol = 'BTCUSDT'
  AND side = 'BUY'
  AND closed_at IS NOT NULL
ORDER BY closed_at DESC;
```

**4. Проверить SELL сделки и их связь с BUY:**

```sql
SELECT 
    s.id as sell_id,
    s.quantity as sell_quantity,
    s.price as sell_price,
    s.created_at as sell_created,
    s.filled_at as sell_filled,
    s.parent_id,
    b.id as buy_id,
    b.quantity as buy_quantity,
    b.closed_at as buy_closed
FROM trades s
LEFT JOIN trades b ON b.id = s.parent_id
WHERE s.symbol = 'BTCUSDT'
  AND s.side = 'SELL'
ORDER BY s.id DESC;
```

### Рекомендации

1. **Запустить синхронизацию ордеров:**
   ```bash
   php artisan orders:sync
   ```

2. **Проверить баланс на бирже:**
   ```bash
   php artisan balance:check BTC --exchange=okx
   ```

3. **Исправить SELL id 1 (если еще не исправлено):**
   ```sql
   UPDATE trades
   SET filled_at = created_at + INTERVAL 5 SECOND
   WHERE id = 1 AND side = 'SELL' AND symbol = 'BTCUSDT';
   ```

4. **Проверить, почему BUY пропускается:**
   - Если `getNetPosition() > 0`, значит есть открытые позиции
   - Нужно проверить, правильно ли они считаются

### Вывод

- **На бирже: 0.00072997 BTC**
- **В БД (предыдущий запрос): 0.00005631 BTC**
- **Расхождение: ~0.00067366 BTC**
- **BUY пропускается** - значит система видит открытые позиции
- **Нужно синхронизировать** и проверить закрытые позиции

### Следующие шаги

1. Выполнить SQL запросы для проверки открытых/закрытых позиций
2. Запустить `orders:sync` для синхронизации
3. Проверить баланс через команду
4. Сравнить результаты и найти причину расхождения
