# Исправление ошибки "The payload is invalid"

## Проблема

Ошибка `The payload is invalid` означает, что `api_secret` был зашифрован с другим `APP_KEY` или данные повреждены.

## Решение

### 1. Убедитесь что APP_KEY сгенерирован:

```bash
php artisan key:generate
php artisan config:clear
```

### 2. Добавьте API ключи в `.env`:

Откройте файл `.env` и добавьте:

```env
# Production Bybit
BYBIT_API_KEY=ваш_production_api_key
BYBIT_API_SECRET=ваш_production_api_secret

# Testnet Bybit
BYBIT_TESTNET_API_KEY=ваш_testnet_api_key
BYBIT_TESTNET_API_SECRET=ваш_testnet_api_secret
```

### 3. Пересоздайте аккаунты:

```bash
# С шифрованием (рекомендуется)
php artisan create-bybit-account --force

# ИЛИ без шифрования (для отладки)
php artisan create-bybit-account --force --no-encrypt
```

### 4. Проверьте:

```bash
# Список всех аккаунтов
php artisan tinker --execute="\App\Models\ExchangeAccount::all(['id', 'exchange', 'is_testnet'])->toArray()"

# Тест конкретного аккаунта
php artisan api:test --account=ID
```

## Важно

- После генерации нового `APP_KEY` все зашифрованные данные нужно пересоздать
- Используйте `--force` чтобы удалить старые аккаунты перед созданием новых
- Если проблема сохраняется, попробуйте `--no-encrypt` для отладки
