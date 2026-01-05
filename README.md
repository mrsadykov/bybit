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

#### Запросы и в чем считаются

Ключевые договорённости (важно зафиксировать)
✅ Что у нас теперь означает:

trading_bots.position_size → USDT, сколько хотим потратить

BUY:

считаем btcQty = position_size / price

в запросе ТОЛЬКО qty (BTC)

SELL:

продаём весь BTC, который есть у бота

trades.quantity → BTC

min_notional_usdt → проверка price * btcQty

❌ quoteOrderQty — НЕ ИСПОЛЬЗУЕМ ВООБЩЕ







