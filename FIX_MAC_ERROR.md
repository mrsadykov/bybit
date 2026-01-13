# 🔧 Исправление ошибки "The MAC is invalid"

## 🔍 Причина ошибки

Ошибка "The MAC is invalid" означает проблему с расшифровкой `api_secret`. Это происходит когда:
1. Данные были зашифрованы другим `APP_KEY`
2. `APP_KEY` изменился после сохранения данных
3. Данные в БД повреждены

## ✅ Решение

### Вариант 1: Пересохранить аккаунты (РЕКОМЕНДУЕТСЯ)

```bash
# Удалите старые аккаунты и создайте заново
php artisan tinker
```

```php
// Удалить старые аккаунты
>>> \App\Models\ExchangeAccount::where('exchange', 'bybit')->delete();

// Выход
>>> exit
```

Затем создайте аккаунты заново:

```bash
php artisan create-bybit-account
```

### Вариант 2: Обновить существующие аккаунты

```bash
php artisan tinker
```

```php
// Обновить testnet аккаунт
>>> $account = \App\Models\ExchangeAccount::find(5);
>>> $account->api_key = config('services.bybit.testnet_key');
>>> $account->api_secret = config('services.bybit.testnet_secret');
>>> $account->save();

// Проверить что расшифровка работает
>>> $account->refresh();
>>> echo 'Secret length: ' . strlen($account->api_secret) . "\n";

>>> exit
```

### Вариант 3: Проверить APP_KEY

```bash
# Проверить что APP_KEY установлен
php artisan tinker --execute="echo 'APP_KEY: ' . (config('app.key') ? 'Set' : 'Not set') . PHP_EOL;"

# Если не установлен, сгенерировать
php artisan key:generate
```

## 🛠️ Пошаговое исправление

### 1. Проверьте APP_KEY

```bash
php artisan key:generate
php artisan config:clear
```

### 2. Пересоздайте аккаунты

```bash
# Удалить старые (если нужно)
php artisan tinker --execute="\App\Models\ExchangeAccount::where('exchange', 'bybit')->delete();"

# Создать заново
php artisan create-bybit-account
```

### 3. Проверьте баланс

```bash
php artisan balance:check --testnet
```

## 🔍 Диагностика

Если проблема сохраняется:

```bash
php artisan tinker
```

```php
// Проверить расшифровку
>>> $account = \App\Models\ExchangeAccount::find(5);
>>> try {
    $secret = $account->api_secret;
    echo "✅ Secret decrypted. Length: " . strlen($secret) . "\n";
} catch (\Exception $e) {
    echo "❌ Decryption failed: " . $e->getMessage() . "\n";
    echo "This means APP_KEY changed or data is corrupted.\n";
    echo "Solution: Delete and recreate accounts.\n";
}

>>> exit
```

## 💡 Почему это происходит

Laravel использует `encrypted` cast для автоматического шифрования/расшифровки. Если:
- `APP_KEY` изменился после сохранения данных
- Данные были зашифрованы другим ключом
- БД была скопирована с другого сервера

То расшифровка не сработает и появится ошибка "The MAC is invalid".

## ✅ Быстрое решение

```bash
# 1. Убедитесь что APP_KEY установлен
php artisan key:generate

# 2. Очистите кэш
php artisan config:clear

# 3. Удалите и пересоздайте аккаунты
php artisan tinker --execute="\App\Models\ExchangeAccount::where('exchange', 'bybit')->delete();"
php artisan create-bybit-account

# 4. Проверьте
php artisan balance:check --testnet
```
