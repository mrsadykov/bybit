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
#### Если сломалась бд? данные не синхронизированы?
```
php artisan trades:recover-bybit --symbol=BTCUSDT
```

#### Если нужно синхронизировать статусы ордеров? (поставить на крон, должен запускаться каждую минуту)
```
php artisan orders:sync
```
```
* * * * * php /path/to/project/artisan schedule:run >> /dev/null 2>&1
```

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








