# Настройка OKX

## Шаг 1: Добавить переменные в .env

Убедитесь что в `.env` добавлены следующие переменные:

```env
OKX_API_KEY=ваш_api_key
OKX_API_SECRET=ваш_api_secret
OKX_API_PASSPHRASE=ваш_passphrase
```

**Важно:**
- `OKX_API_PASSPHRASE` - это пароль, который вы указывали при создании API ключа на OKX
- Если забыли passphrase, нужно создать новый API ключ

## Шаг 2: Создать аккаунт в БД

```bash
php artisan create-okx-account
```

## Шаг 3: Протестировать подключение

```bash
php artisan okx:test
```

## Шаг 4: Проверить баланс

```bash
php artisan balance:check USDT --exchange=okx
```

## Что дальше?

После успешного тестирования можно:
1. Создать торговый бот с OKX аккаунтом
2. Начать торговлю
3. Использовать все функции как с Bybit
