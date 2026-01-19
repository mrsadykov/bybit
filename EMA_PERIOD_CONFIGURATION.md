# 📊 Настройка EMA периода для стратегии

## 🔍 Текущая ситуация

**EMA период захардкожен в коде:**
- Файл: `app/Trading/Strategies/RsiEmaStrategy.php`
- Строка 13: `$ema = EmaIndicator::calculate($closes, 10);`
- **Текущее значение: 10**

---

## ✅ Решение 1: Изменить напрямую в коде (быстро)

### Для всех ботов (глобально):

Откройте `app/Trading/Strategies/RsiEmaStrategy.php` и измените:

```php
// Было:
$ema = EmaIndicator::calculate($closes, 10);

// Стало (для SOLUSDT рекомендуется 15-20):
$ema = EmaIndicator::calculate($closes, 15);
// или
$ema = EmaIndicator::calculate($closes, 20);
```

**⚠️ Проблема:** Это изменит EMA период для ВСЕХ ботов (BTC, ETH, SOL).

---

## ✅ Решение 2: Сделать настраиваемым через БД (рекомендую)

### Шаг 1: Создать миграцию для добавления полей

```bash
php artisan make:migration add_rsi_ema_periods_to_trading_bots_table
```

### Шаг 2: В миграции добавить поля:

```php
Schema::table('trading_bots', function (Blueprint $table) {
    $table->integer('rsi_period')->nullable()->after('strategy');
    $table->integer('ema_period')->nullable()->after('rsi_period');
});
```

### Шаг 3: Обновить модель TradingBot

В `app/Models/TradingBot.php` добавить в `$fillable`:
```php
'rsi_period',
'ema_period',
```

### Шаг 4: Изменить RsiEmaStrategy

```php
public static function decide(array $closes, ?int $rsiPeriod = null, ?int $emaPeriod = null): string
{
    $rsiPeriod = $rsiPeriod ?? 17; // По умолчанию
    $emaPeriod = $emaPeriod ?? 10; // По умолчанию
    
    $rsi = RsiIndicator::calculate($closes, $rsiPeriod);
    $ema = EmaIndicator::calculate($closes, $emaPeriod);
    
    // ... остальной код
}
```

### Шаг 5: Обновить RunTradingBotsCommand

```php
$rsiPeriod = $bot->rsi_period ?? 17;
$emaPeriod = $bot->ema_period ?? 10;

$signal = RsiEmaStrategy::decide($closes, $rsiPeriod, $emaPeriod);
```

### Шаг 6: Установить значения для ботов

```bash
php artisan tinker
```

```php
// Для SOLUSDT бота (Bot #3)
$bot = \App\Models\TradingBot::find(3);
$bot->rsi_period = 17;
$bot->ema_period = 15; // или 20
$bot->save();

// Для BTCUSDT бота (Bot #1) - оставить дефолтные
$bot = \App\Models\TradingBot::find(1);
$bot->rsi_period = 17;
$bot->ema_period = 10;
$bot->save();
```

---

## ✅ Решение 3: Создать отдельную стратегию для SOLUSDT

Создать `RsiEmaStrategySol.php` с EMA периодом 15-20.

---

## 🎯 Рекомендация

**Для быстрого решения (если только SOLUSDT):**
- Измените EMA период в `RsiEmaStrategy.php` на 15-20
- Или создайте отдельную стратегию

**Для гибкости (если несколько ботов с разными параметрами):**
- Реализуйте Решение 2 (настраиваемые параметры через БД)

---

## 📋 Текущие значения в коде

- **RsiEmaStrategy.php:** RSI=17, EMA=10
- **RunTradingBotsCommand.php:** RSI (дефолт), EMA=20 (только для отображения!)

**⚠️ Внимание:** В `RunTradingBotsCommand` EMA=20 используется только для отображения, реальная стратегия использует EMA=10 из `RsiEmaStrategy`!
