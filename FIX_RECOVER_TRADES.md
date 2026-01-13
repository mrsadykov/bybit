# Исправление команды RecoverBybitTradesCommand

## Проблема

Команда `trades:recover-bybit` не возвращает ордера, хотя в Bybit есть завершенные ордера.

## Причина

Команда использует `getExecutions()`, который возвращает **исполнения (executions)**, а не **историю ордеров (orders)**. Для восстановления ордеров нужно использовать `getOrdersHistory()`.

## Что исправлено

1. **Исправлен метод `getOrdersHistory()`** - теперь правильно принимает параметр `symbol`
2. **Исправлена команда** - теперь использует `getOrdersHistory()` вместо `getExecutions()`
3. **Добавлено логирование** - показывает сколько ордеров найдено

## Использование

```bash
php artisan trades:recover-bybit --symbol=BTCUSDT
```

## Примечание

Убедитесь что:
- APP_KEY сгенерирован: `php artisan key:generate`
- API ключи настроены правильно
- Аккаунты созданы: `php artisan create-bybit-account`
