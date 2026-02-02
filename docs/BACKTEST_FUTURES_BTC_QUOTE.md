# Бэктест фьючерсов и пар за BTC (п.8)

## Команды

### Фьючерсы (OKX perpetual swap)

- **Один символ:**  
  `php artisan strategy:futures-backtest BTCUSDT --timeframe=1h --period=500`

- **Все фьючерсные боты из БД:**  
  `php artisan strategy:futures-backtest-all --period=500 [--output=results.json]`

Параметры по умолчанию: RSI 17, EMA 10, пороги 40/60, позиция 100 USDT, комиссия 0.05%.  
Размер контракта берётся из `config/futures.php` (contract_sizes).  
Свечи запрашиваются с OKX по инструменту `BTC-USDT-SWAP` и т.д.

### Пары за BTC (OKX spot XXX-BTC)

- **Один символ:**  
  `php artisan strategy:btc-quote-backtest SOLBTC --timeframe=1h --period=500`

- **Все BTC-quote боты из БД:**  
  `php artisan strategy:btc-quote-backtest-all --period=500 [--output=results.json]`

Параметры по умолчанию: RSI 17, EMA 10, пороги 40/60, позиция 0.01 BTC, комиссия 0.1%.  
Баланс и PnL в BTC; в конце выводится эквивалент в USDT по текущему курсу BTC-USDT.

## Опции (одиночный бэктест)

**Фьючерсы:**  
`--timeframe`, `--period`, `--rsi-period`, `--ema-period`, `--rsi-buy`, `--rsi-sell`, `--position-usdt`, `--fee`, `--json`

**BTC-quote:**  
`--timeframe`, `--period`, `--rsi-period`, `--ema-period`, `--rsi-buy`, `--rsi-sell`, `--position-btc`, `--fee`, `--json`

## Примеры

```bash
# Фьючерс BTC, 500 свечей 1h
php artisan strategy:futures-backtest BTCUSDT --period=500 --position-usdt=50

# Пара SOL-BTC, 300 свечей
php artisan strategy:btc-quote-backtest SOLBTC --period=300 --position-btc=0.005

# Все фьючерсные боты, сохранить JSON
php artisan strategy:futures-backtest-all --period=500 --output=storage/futures_backtest.json

# Все боты за BTC
php artisan strategy:btc-quote-backtest-all --period=500
```

## Логика

- Стратегия та же, что в споте: RSI + EMA (и опционально допуск из `config/trading`).  
- Фьючерсы: только long, симулируется открытие/закрытие контрактов, PnL в USDT.  
- BTC-quote: баланс в BTC, покупка/продажа базового актива (например SOL), PnL в BTC и в USDT для сводки.
