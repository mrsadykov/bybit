# Финальная диагностика ошибки 401

## Текущая ситуация

✅ **Что работает:**
- Публичный API работает (получение цены BTC)
- API Secret расшифровывается успешно
- Подпись формируется правильно (видно в логах)
- Endpoint `/v5/account/wallet-balance` правильный

❌ **Что не работает:**
- Приватный API возвращает 401 Unauthorized

## Вывод

Проблема **НЕ в коде**, а в **настройках API ключа на Bybit**.

## Что проверить на bybit.com

### 1. Права API ключа

1. Зайдите на https://www.bybit.com
2. Перейдите в **API Management**
3. Найдите ваш API ключ
4. Убедитесь что включены права:
   - ✅ **Read** (обязательно!)
   - ✅ **Spot Trading** (если планируете торговать)

### 2. IP Whitelist

1. В настройках API ключа проверьте **IP Whitelist**
2. Если включен - **отключите для теста**
3. Или добавьте ваш текущий IP адрес

### 3. Тип аккаунта

1. Проверьте тип вашего аккаунта на Bybit:
   - **Spot Account** (классический)
   - **Unified Trading Account** (ETA)
2. Если у вас Unified Account, используйте `accountType=UNIFIED`
3. Если Spot Account, используйте `accountType=SPOT`

### 4. Окружение (Testnet vs Production)

1. Убедитесь что используете правильные ключи:
   - **Production ключи** → `https://api.bybit.com`
   - **Testnet ключи** → `https://api-testnet.bybit.com`
2. Не смешивайте ключи из разных окружений!

### 5. Создайте новый API ключ

Если ничего не помогает:
1. Удалите старый API ключ
2. Создайте новый с правами **Read**
3. **Отключите IP Whitelist** для теста
4. Обновите `.env` и пересоздайте аккаунт:
   ```bash
   php artisan create-bybit-account --force
   ```

## Команды для диагностики

```bash
# Детальная отладка подписи
php artisan debug:signature --account=18

# Тест API подключения
php artisan api:test --account=18

# Проверка баланса
php artisan balance:check USDT --account=18
```

## Логи

Проверьте логи для детальной информации:
```bash
tail -f storage/logs/laravel.log | grep "Bybit API"
```

## Важно

Если вы видите в логах:
- `payload: "timestamp + apiKey + recvWindow + queryString"` ✅ - подпись формируется правильно
- `sign: "..."` ✅ - подпись вычисляется
- Но все равно 401 ❌ - проблема в настройках API ключа на Bybit

## Следующие шаги

1. Проверьте права API ключа на bybit.com
2. Отключите IP Whitelist
3. Если не поможет - создайте новый API ключ
4. Обновите `.env` и пересоздайте аккаунт
