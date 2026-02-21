# Рекомендации по position_size и параметрам по каждому боту

Цель: снизить долю комиссии в PnL. Рекомендуемый **фактический** размер на сделку (после множителя): **15–25 USDT** для спот-ботов.

В коде используется: `фактический размер = position_size × TRADING_POSITION_SIZE_MULTIPLIER`.

---

## Таблица: текущие и рекомендуемые значения

| Бот | Символ | ТФ | Текущий position_size | Фактический сейчас (×0.5) | Рекомендуемый position_size | Фактический будет (×0.5) | Другие параметры |
|-----|--------|-----|----------------------|---------------------------|-----------------------------|---------------------------|------------------|
| 1 | BTCUSDT | 1h | 10 | 5 USDT | **40** | 20 USDT | Оставить SL 4%, TP 8% |
| 2 | ETHUSDT | 1h | 5 | 2.5 USDT | **40** | 20 USDT | Оставить SL 2%, TP 4% |
| 3 | SOLUSDT | 1h | 10 | 5 USDT | **40** | 20 USDT | Оставить SL 6%, TP 12% |
| 4 | BNBUSDT | 1h | 5 | 2.5 USDT | **40** | 20 USDT | Опционально: SL 2.5%, TP 5% (чуть реже выходы) |
| 5 | ETHUSDT | 4h | 5 | 2.5 USDT | **50** | 25 USDT | Оставить SL 5%, TP 10% |

Если используете **множитель 1** (без уменьшения в .env), ставьте position_size: **20, 20, 20, 20, 25** соответственно — тогда фактический размер будет 20/20/20/20/25 USDT.

---

## Что поменять кроме position_size

1. **TRADING_POSITION_SIZE_MULTIPLIER**  
   - Сейчас, скорее всего, `0.5` в .env.  
   - Можно оставить 0.5 и поднять position_size до 40/50, либо поставить `1` и использовать 20/25.

2. **max_daily_loss_usdt** (в боте)  
   - Сейчас у всех 10. При размере 20 USDT одна полная потеря по SL (4%) ≈ 0.8 USDT; 2% ≈ 0.4 USDT.  
   - Имеет смысл поднять лимит до **15–20 USDT**, чтобы не выключать ботов после нескольких стопов. Опционально.

3. **BNB (бот 4)**  
   - RSI 45/55 уже узкий — менять не обязательно.  
   - При желании чуть реже выходить по TP/SL: **stop_loss_percent 2.5**, **take_profit_percent 5** (вместо 2% / 4%).

4. **Остальные параметры** (RSI, EMA, пороги) — без изменений, бэктест по 1000 свечам дал мало сделок; подстраивать под историю не стоит.

---

## Как применить

### Вариант A: через веб-интерфейс

В разделе редактирования каждого бота задать **Position size (USDT)** по таблице выше (40 или 50 при множителе 0.5; 20 или 25 при множителе 1). При необходимости изменить max daily loss и для BNB — SL/TP.

### Вариант B: SQL (продакшн БД)

```sql
UPDATE trading_bots SET position_size = 40 WHERE id = 1;
UPDATE trading_bots SET position_size = 40 WHERE id = 2;
UPDATE trading_bots SET position_size = 40 WHERE id = 3;
UPDATE trading_bots SET position_size = 40 WHERE id = 4;
UPDATE trading_bots SET position_size = 50 WHERE id = 5;
```

Опционально (max_daily_loss и BNB SL/TP):

```sql
UPDATE trading_bots SET max_daily_loss_usdt = 20 WHERE id IN (1,2,3,4,5);
UPDATE trading_bots SET stop_loss_percent = 2.5, take_profit_percent = 5 WHERE id = 4;
```

### Вариант C: .env

- Оставить или убрать уменьшение размера:  
  `TRADING_POSITION_SIZE_MULTIPLIER=0.5` (фактически 20 USDT при position_size 40)  
  или  
  `TRADING_POSITION_SIZE_MULTIPLIER=1` (и тогда в БД ставить 20/25).

---

## Итог

| Бот | Рекомендуемый position_size | При множителе 0.5 | При множителе 1 |
|-----|----------------------------|-------------------|-----------------|
| 1 BTCUSDT 1h | 40 | 20 USDT | 40 USDT |
| 2 ETHUSDT 1h | 40 | 20 USDT | 40 USDT |
| 3 SOLUSDT 1h | 40 | 20 USDT | 40 USDT |
| 4 BNBUSDT 1h | 40 | 20 USDT | 40 USDT |
| 5 ETHUSDT 4h | 50 | 25 USDT | 50 USDT |

Остальные параметры по желанию: max_daily_loss_usdt 15–20; для BNB опционально SL 2.5%, TP 5%.
