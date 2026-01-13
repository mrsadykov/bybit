# Исправление отображения баланса для Testnet

## Проблема

Для testnet аккаунта API возвращает пустой массив монет (`coin: []`), хотя на сайте баланс есть (101,343.28 USD).

## Что видно в логах

```
Bybit getBalance: No coins in account
{
  "account": {
    "coin": [],  // ← Пустой массив!
    "totalWalletBalance": "0",
    "totalEquity": "0"
  }
}
```

## Возможные причины

1. **API ключ testnet не имеет прав** - проверьте права на testnet.bybit.com
2. **Нужен другой accountType** - возможно для testnet нужен SPOT вместо UNIFIED
3. **Монеты в другом формате** - возможно баланс возвращается в другом поле
4. **Проблема с правами API** - API ключ может не иметь доступа к балансу

## Решение

### 1. Проверьте права API ключа на testnet

Зайдите на https://testnet.bybit.com → API Management и убедитесь что:
- ✅ Включено право **Read**
- ✅ IP Whitelist отключен (или ваш IP добавлен)

### 2. Проверьте что используете правильный API ключ

Убедитесь что в `.env` указаны правильные testnet ключи:
```env
BYBIT_TESTNET_API_KEY=ваш_testnet_key
BYBIT_TESTNET_API_SECRET=ваш_testnet_secret
```

### 3. Пересоздайте аккаунт

```bash
php artisan key:generate
php artisan config:clear
php artisan create-bybit-account --force
```

### 4. Проверьте баланс снова

```bash
php artisan balance:check USDT --testnet
```

## Что исправлено в коде

Добавлена обработка случая, когда `coin` массив пустой, но `totalWalletBalance` > 0. В этом случае для USDT возвращается `totalWalletBalance`.

## Диагностика

Если проблема сохраняется, проверьте логи:
```bash
tail -f storage/logs/laravel.log | grep "Bybit getBalance"
```

Или используйте команду отладки:
```bash
php artisan debug:signature --account=21
```

## Важно

- Testnet API может иметь ограничения по сравнению с production
- Убедитесь что API ключ создан на testnet.bybit.com, а не на bybit.com
- Проверьте что баланс действительно есть на сайте testnet.bybit.com
