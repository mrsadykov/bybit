## Установка

### 1. Начальные действия
``` 
composer install
php artisan migrate
npm install
npm run build 
```

### 2. Запуск команд - создание пользователя, создание настроек API-аккаунта
```
php artisan setup
```

### 3. Создание торгового бота для bybit
```
php artisan bybit-bot:create BTCUSDT 5 rsi_ema 0.00002
```

### 4. Запуск всех активных торговых ботов (без торговли)
```
php artisan bots:run
```

### 5. 


### Дополнительная информация

#### Связь моделей

User
└── ExchangeAccount (Bybit API)
└── TradingBot
└── Trade (много сделок)

#### Position Manager

Position Manager — это слой, который отвечает за вопрос:
“Можно ли сейчас делать BUY или SELL?”

Индикаторы → Стратегия → Сигнал
↓
Position Manager
↓
Разрешить / Запретить
↓
Trade / Order

#### Этапы жизни ордера

PENDING → SENT → FILLED / PARTIALLY_FILLED / FAILED

### Команды

#### Настройка и установка

**`php artisan setup`**
- Выполняет начальную настройку проекта
- Создает администратора и API аккаунты Bybit
- Запускает `create-admin` и `create-bybit-account`

**`php artisan create-admin`**
- Создает административного пользователя
- Использует данные из `config/app.php` (admin.email, admin.name, admin.password)

**`php artisan create-bybit-account [--force] [--no-encrypt]`**
- Создает или обновляет аккаунты Bybit (production и testnet)
- Берет API ключи из `.env`:
  - `BYBIT_API_KEY` / `BYBIT_API_SECRET` (production)
  - `BYBIT_TESTNET_API_KEY` / `BYBIT_TESTNET_API_SECRET` (testnet)
- `--force` - удаляет старые аккаунты перед созданием
- `--no-encrypt` - создает без шифрования (только для отладки!)

#### Торговые боты

**`php artisan bybit-bot:create {symbol} {timeframe} {strategy} {position_size}`**
- Создает новый торговый бот для Bybit
- Параметры:
  - `symbol` - торговая пара (например: BTCUSDT)
  - `timeframe` - таймфрейм (1, 3, 5, 15, 30, 60, 120, 240, 360, 720, D, M, W)
  - `strategy` - стратегия (rsi_ema)
  - `position_size` - размер позиции в USDT (например: 10)
- Пример: `php artisan bybit-bot:create BTCUSDT 5m rsi_ema 10`

**`php artisan okx-bot:create {symbol} {timeframe} {strategy} {position_size}`**
- Создает новый торговый бот для OKX
- Параметры:
  - `symbol` - торговая пара (например: BTCUSDT)
  - `timeframe` - таймфрейм (1, 3, 5, 15, 30, 60, 120, 240, 360, 720, D, M, W)
  - `strategy` - стратегия (rsi_ema)
  - `position_size` - размер позиции в USDT (например: 10)
- Пример: `php artisan okx-bot:create BTCUSDT 5m rsi_ema 10`
- Требует: OKX аккаунт должен быть создан (`php artisan create-okx-account`)

**`php artisan bots:run`**
- Запускает все активные торговые боты
- Выполняет торговую логику: получение цены, свечей, индикаторов, сигналов
- Размещает ордера BUY/SELL при соответствующих сигналах
- Рекомендуется запускать через cron каждые 1-5 минут

#### Синхронизация и восстановление

**`php artisan orders:sync`**
- Синхронизирует статусы ордеров с биржей
- Обновляет статусы: PENDING → SENT → FILLED/PARTIALLY_FILLED/FAILED
- Рассчитывает PnL при закрытии позиций
- **Важно:** Должен запускаться через cron каждую минуту!
- Пример cron: `* * * * * php /path/to/project/artisan orders:sync >> /dev/null 2>&1`

**`php artisan trades:recover-bybit [--symbol={SYMBOL}] [--all]`**
- Восстанавливает пропущенные сделки из истории Bybit
- Используется если данные в БД не синхронизированы с биржей
- `--symbol` - символ для восстановления (например: BTCUSDT)
- `--all` - получить все ордера без фильтра по символу
- Пример: `php artisan trades:recover-bybit --symbol=BTCUSDT`
- ⚠️ **Ограничение**: Bybit API возвращает только ордера за последние 7 дней

**`php artisan position:sync-from-balance [--bot=ID] [--force]`**
- Синхронизирует позицию бота с реальным балансом на бирже
- Полезно когда история ордеров недоступна (ордера старше 7 дней)
- `--bot` - ID бота для синхронизации (необязательно)
- `--force` - создать синтетическую сделку для синхронизации позиции
- Пример: `php artisan position:sync-from-balance --bot=20 --force`

#### Диагностика и проверка

**`php artisan balance:check {coin} [--account=ID] [--testnet] [--production]`**
- Проверяет баланс на аккаунте Bybit
- Параметры:
  - `coin` - монета для проверки (по умолчанию: USDT)
  - `--account=ID` - ID конкретного аккаунта
  - `--testnet` - использовать testnet аккаунт
  - `--production` - использовать production аккаунт
- Примеры:
  - `php artisan balance:check USDT --account=20`
  - `php artisan balance:check BTC --testnet`

**`php artisan api:test [--account=ID]`**
- Тестирует подключение к Bybit API
- Проверяет:
  - Расшифровку API ключей
  - Публичный API (получение цены)
  - Приватный API (получение баланса)
- Показывает детальную диагностику при ошибках
- Пример: `php artisan api:test --account=20`

**`php artisan debug:signature [--account=ID]`**
- Детальная отладка формирования подписи для API запросов
- Показывает:
  - Формирование payload
  - Вычисление подписи
  - Отправку тестового запроса
  - Ответ от API
- Используется для диагностики проблем с аутентификацией
- Пример: `php artisan debug:signature --account=20`

#### Запросы и в чем считаются

#### до шага 9.4

Порядок действий правильный:

Получили цену

Получили свечи

Посчитали индикаторы

Получили сигнал

Проверили позицию (PositionManager)

Создали trade до запроса в биржу

Отправили market BUY

Проверили retCode

Сохранили order_id

Проверили статус ордера (шаг 9.4)

Обновили trade → FILLED / SENT / FAILED

#### Жизненный цикл ордера BUY 
[RunTradingBotsCommand]
BUY сигнал
→ trade PENDING
→ placeMarketBuy
→ trade SENT

(через 5 сек / 30 сек)

[SyncOrdersCommand]
→ order Filled
→ trade FILLED

#### Жизненный цикл ордера sell
[RunTradingBotsCommand]
SELL сигнал
→ create SELL trade (parent_id)
→ placeMarketSell
→ trade SENT

[SyncOrdersCommand]
→ FILLED

#### Общая логика SELL (spot)

1. Найти открытый BUY
2. Понять, сколько можно продать
3. Создать запись SELL (PENDING)
4. Отправить market SELL на Bybit
5. Сохранить order_id
6. Дать бирже исполниться
Через SyncOrdersCommand:
- обновить SELL
- посчитать PnL
- закрыть BUY

#### Итоговая схема (идеальная)
RunTradingBotsCommand
├─ BUY decision
├─ SELL decision
└─ create trades + send orders

orders:sync
├─ sync statuses
├─ close BUY
└─ calc PnL

Положительный PnL у BUY ордера означает прибыль.


ttt



