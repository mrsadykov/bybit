# Проверка параметров ботов в SQL-дампах

Проверены файлы: `trading_bots (9).sql`, `futures_bots (4).sql`, `btc_quote_bots (2).sql` — сверка с миграциями и моделями.

---

## 1. trading_bots (9).sql

**Колонки в INSERT:** id, created_at, updated_at, user_id, exchange_account_id, symbol, timeframe, strategy, rsi_period, ema_period, rsi_buy_threshold, rsi_sell_threshold, position_size, stop_loss_percent, take_profit_percent, max_daily_loss_usdt, max_drawdown_percent, risk_drawdown_reset_at, max_losing_streak, use_macd_filter, is_active, last_trade_at, dry_run.

**Итог:** Все колонки совпадают со схемой таблицы `trading_bots`. Порядок в INSERT может отличаться — для MySQL это допустимо (указаны имена колонок).

**Значения по строкам:**

| id | symbol   | rsi buy/sell | position_size | SL% | TP% | max_daily_loss_usdt | max_drawdown% | max_losing_streak | use_macd | is_active | dry_run |
|----|----------|--------------|---------------|-----|-----|----------------------|---------------|------------------|----------|-----------|---------|
| 1  | BTCUSDT  | 38/62        | 10            | 3   | 6   | 10                   | 60            | 3                | 1        | 1         | 0       |
| 2  | ETHUSDT  | 38/62        | 5             | 3   | 6   | 10                   | 20            | 3                | 0        | 1         | 0       |
| 3  | SOLUSDT  | 38/62        | 10            | 6   | 12  | 10                   | 20            | NULL             | 1        | 1         | 0       |
| 4  | BNBUSDT  | 45/55        | 5             | 5   | 10  | 10                   | 20            | NULL             | 0        | 1         | 0       |

- RSI: везде sell > buy (62>38, 55>45) — корректно.
- position_size, проценты, лимиты — в разумных границах.
- **Замечаний нет.**

---

## 2. futures_bots (4).sql

**Колонки в INSERT:** id, created_at, updated_at, user_id, exchange_account_id, symbol, timeframe, strategy, rsi_period, ema_period, rsi_buy_threshold, rsi_sell_threshold, position_size_usdt, leverage, stop_loss_percent, take_profit_percent, max_daily_loss_usdt, max_losing_streak, is_active, dry_run, last_trade_at.

**Итог:** Соответствуют схеме таблицы `futures_bots` (включая `max_daily_loss_usdt`, `max_losing_streak` из миграции 2026_02_12).

**Значения:**

| id | symbol   | position_size_usdt | leverage | max_daily_loss_usdt | max_losing_streak | is_active | dry_run |
|----|----------|--------------------|----------|----------------------|-------------------|-----------|---------|
| 4  | BTCUSDT  | 500                | 2        | 15                   | 3                 | 0         | 0       |
| 5  | ETHUSDT  | 100                | 2        | 15                   | 3                 | 0         | 0       |

- Лимиты 15 USDT и 3 сделки подряд — адекватно.
- **Замечаний нет.**

---

## 3. btc_quote_bots (2).sql

**Колонки в INSERT:** id, created_at, updated_at, user_id, exchange_account_id, symbol, timeframe, strategy, position_size_btc, rsi_period, ema_period, rsi_buy_threshold, rsi_sell_threshold, stop_loss_percent, take_profit_percent, max_daily_loss_btc, max_losing_streak, is_active, dry_run, last_trade_at.

**Итог:** Соответствуют схеме таблицы `btc_quote_bots` (включая `max_daily_loss_btc`, `max_losing_streak`).

**Значения:**

| id | symbol  | position_size_btc | max_daily_loss_btc | max_losing_streak | is_active | dry_run |
|----|---------|-------------------|---------------------|-------------------|-----------|---------|
| 3  | SOLBTC  | 0.0005            | **15.00000000**     | 3                 | 1         | 0       |
| 4  | ETHBTC  | 0.0005            | **15.00000000**     | 3                 | 1         | 0       |

- **Важно:** `max_daily_loss_btc = 15` означает лимит **15 BTC** дневного убытка. При размере позиции 0.0005 BTC это фактически «без лимита» (очень большое значение). Если имелось в виду ограничение в BTC-эквиваленте (например, ~15 USDT ≈ 0.00015–0.0002 BTC), то в дампе лучше указать что-то вроде **0.00015** или **0.0002**, а не 15. Если 15 BTC задумано осознанно — менять не нужно.

---

## Итог

- **trading_bots (9).sql** — колонки и значения в порядке, замечаний нет.
- **futures_bots (4).sql** — колонки и значения в порядке, замечаний нет.
- **btc_quote_bots (2).sql** — колонки совпадают со схемой; по смыслу стоит проверить, не опечатка ли **15** в `max_daily_loss_btc` (возможный вариант: **0.00015** или **0.0002** BTC вместо 15 BTC).

После правок (если нужно) можно снова импортировать дампы или обновить только нужные строки в БД.

---

## 4. trading_bots (12).sql (продакшен после всех изменений)

**Колонки:** совпадают со схемой `trading_bots`.

| id | symbol     | timeframe | RSI   | SL% | TP% | position | max_daily | max_losing_streak | use_macd | is_active | dry_run |
|----|------------|-----------|--------|-----|-----|----------|-----------|-------------------|----------|-----------|---------|
| 1  | BTCUSDT    | 1h        | 38/62  | 4   | 8   | 10       | 10        | 3                 | 1        | 1         | 0       |
| 2  | ETHUSDT    | 1h        | 38/62  | 2   | 4   | 5        | 10        | 3                 | 0        | 1         | 0       |
| 3  | SOLUSDT    | 1h        | 38/62  | 6   | 12  | 10       | 10        | NULL              | 1        | **0**    | **1**   |
| 4  | BNBUSDT    | 1h        | 45/55  | 2   | 4   | 5        | 10        | NULL              | 0        | 1         | 0       |
| 5  | ETHUSDT    | 4h        | 38/62  | 5   | 10  | 5        | 10        | 3                 | 0        | 1         | 0       |

- SL/TP приведены к результатам оптимизации (BTC 4/8, ETH 2/4, BNB 2/4, ETH 4h 5/10). SOL отключён (is_active=0) и в dry_run=1 — риск по худшей паре снижен.
- RSI: 38/62 для BTC, ETH, SOL; 45/55 для BNB. Для ETH 4h при желании можно поставить 45/55 по результатам сетки.
- **Замечаний нет.**
