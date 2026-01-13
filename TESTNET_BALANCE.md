# Проверка баланса на Testnet

## Проблема

Для testnet аккаунта баланс показывает 0, хотя должен показывать тестовые монеты.

## Возможные причины

### 1. На testnet аккаунте действительно нет средств

Testnet баланс нужно **запросить** на сайте Bybit:
1. Зайдите на https://testnet.bybit.com
2. Перейдите в раздел **Assets** → **Deposit**
3. Нажмите **Get Testnet Coins** или **Request Testnet Funds**
4. Выберите монеты (USDT, BTC и т.д.)
5. Подтвердите запрос

### 2. Testnet аккаунт тоже использует UNIFIED

Современные testnet аккаунты Bybit тоже используют `UNIFIED` accountType, как и production.

### 3. API ключи testnet не имеют прав

Убедитесь что testnet API ключи имеют права **Read**.

## Как проверить

### 1. Проверьте баланс через команду:

```bash
php artisan balance:check USDT --testnet
```

### 2. Проверьте через API тест:

```bash
php artisan api:test --account=TESTNET_ACCOUNT_ID
```

### 3. Проверьте логи (если включен debug):

```bash
tail -f storage/logs/laravel.log | grep "Bybit getBalance"
```

### 4. Проверьте на сайте:

Зайдите на https://testnet.bybit.com и проверьте баланс вручную.

## Решение

### Если баланс действительно 0:

1. Запросите тестовые монеты на https://testnet.bybit.com
2. Подождите несколько минут
3. Проверьте баланс снова

### Если баланс есть на сайте, но не показывается в приложении:

1. Проверьте что используете правильный testnet API ключ
2. Проверьте что API ключ имеет права Read
3. Проверьте логи для диагностики

## Важно

- Testnet баланс **не связан** с production балансом
- Testnet монеты **бесплатные** и используются только для тестирования
- Testnet API ключи **отдельные** от production ключей
