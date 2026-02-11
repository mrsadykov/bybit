# .env и улучшения (фьючерсы, btc-quote, риск)

## 1. Важно про RSI на споте (TRADING_CONSERVATIVE_RSI)

При **TRADING_CONSERVATIVE_RSI=true** пороги RSI для **спот-ботов** берутся не из БД (38/62, 45/55), а **подменяются на 35/65** глобально. То есть результат `strategy:optimize-all` и `strategy:apply-optimize` для спота в этом режиме **не используется** — действуют 35/65.

**Рекомендация:** если хочешь, чтобы оптимизация реально применялась (38/62, 45/55 по символам), отключи консервативный RSI в `.env`:
```env
TRADING_CONSERVATIVE_RSI=false
```
После смены выполни `php artisan config:clear`.

## 2. Текущий .env (кратко)

- **REAL_TRADING=true** — реальная торговля (OKX ключи используются).
- **BYBIT_ENV=testnet** — Bybit только тестнет; основной торговый счёт — OKX.
- Алерты, пауза при дневном убытке 15 USDT, множитель 0.5, глобальный SL 2%, консервативный RSI, трейлинг-стоп, фильтр тренда — настроены разумно для ограничения риска.
- Фильтр объёма закомментирован — ок, меньше лишних ограничений на вход.

## 3. Фьючерсы и btc-quote: нужны ли max_daily_loss, max_losing_streak, position_size?

- **position_size** — уже есть: у фьючерсов **position_size_usdt**, у btc-quote **position_size_btc**. Оба учитываются в командах запуска и множителе из конфига.
- **max_daily_loss** и **max_losing_streak** — по умолчанию в таблицах фьючерсов и btc-quote их не было; действовала только **глобальная** пауза при дневном убытке по типу ботов (TRADING_PAUSE_NEW_OPENS_DAILY_LOSS_USDT и, для btc-quote, алерт по BTC). Для более точного контроля риска по каждому боту добавлены поля:
  - **futures_bots:** `max_daily_loss_usdt`, `max_losing_streak`
  - **btc_quote_bots:** `max_daily_loss_btc`, `max_losing_streak`

После миграций их можно задавать в дашборде (редактирование бота); при достижении лимита по боту новые BUY по этому боту пропускаются.

## 4. Восстановление удалённых фьючерсных ботов и ботов за BTC

Если фьючерсные боты или боты за BTC были случайно удалены, их можно заново создать с дефолтными настройками:

```bash
# Один фьючерсный бот (BTCUSDT) и один бот за BTC (SOLBTC), первый OKX-аккаунт
php artisan bots:restore-futures-and-btc-quote

# Указать аккаунт и несколько символов
php artisan bots:restore-futures-and-btc-quote --account=2 --futures=BTCUSDT,ETHUSDT --btc-quote=SOLBTC,ETHBTC

# Создать в dry-run и не активировать
php artisan bots:restore-futures-and-btc-quote --dry-run
```

После создания настройки можно изменить в интерфейсе (Фьючерсы / Боты за BTC).

**Восстановление из SQL-дампов** (если есть файлы `futures_bots (3).sql`, `btc_quote_bots (1).sql` и т.п.):

```bash
# Из корня проекта (подставь пользователя и имя БД из .env)
mysql -u USER -p DATABASE < "futures_bots (3).sql"
mysql -u USER -p DATABASE < "btc_quote_bots (1).sql"
```

Если в таблицах уже есть строки с такими же `id`, будет ошибка дубликата — тогда либо удали существующие записи, либо отредактируй в SQL файле значения `id` на свободные.

## 5. Что ещё можно улучшить

- У спот-ботов в дашборде проверить и при необходимости задать **max_daily_loss_usdt** и **max_losing_streak** по каждому боту.
- Раз в 1–2 недели запускать **strategy:optimize-all** и **strategy:apply-optimize** (если не используешь conservative RSI — иначе пороги из оптимизации не применятся).
- Фьючерсы: **FUTURES_DRY_RUN_DEFAULT=true** — новые боты по умолчанию в dry-run; для реальной торговли переключать вручную в дашборде.
- При желании включить **фильтр MACD** на части спот-ботов и смотреть статистику (предварительно сравнить на бэктесте с MACD и без).
