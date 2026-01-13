# 🔧 Настройка .env для двух аккаунтов Bybit

## 📝 Переменные окружения

Добавьте в ваш `.env` файл:

```env
# Production Bybit (bybit.com)
BYBIT_API_KEY=your_production_api_key_here
BYBIT_API_SECRET=your_production_api_secret_here

# Testnet Bybit (testnet.bybit.com)
BYBIT_TESTNET_API_KEY=your_testnet_api_key_here
BYBIT_TESTNET_API_SECRET=your_testnet_api_secret_here

# Режим торговли (по умолчанию false для безопасности)
REAL_TRADING=false

# Bybit окружение (testnet | production)
BYBIT_ENV=testnet
```

## 🔑 Где получить API ключи

### Production (bybit.com):
1. Зайдите на https://www.bybit.com/
2. API Management → Create New Key
3. Скопируйте API Key и Secret Key

### Testnet (testnet.bybit.com):
1. Зайдите на https://testnet.bybit.com/
2. API Management → Create New Key
3. Скопируйте API Key и Secret Key

## ⚠️ Важно

- **Secret Key показывается только один раз!** Сохраните его сразу
- Для testnet используйте ключи от testnet.bybit.com
- Для production используйте ключи от bybit.com
- Не путайте ключи между testnet и production

## 🚀 После настройки

```bash
# Очистить кэш конфигурации
php artisan config:clear

# Создать аккаунты
php artisan create-bybit-account

# Проверить баланс testnet
php artisan balance:check --testnet

# Проверить баланс production
php artisan balance:check --production
```
